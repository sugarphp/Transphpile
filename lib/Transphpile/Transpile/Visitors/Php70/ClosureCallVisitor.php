<?php

namespace Transphpile\Transpile\Visitors\Php70;

use Transphpile\Transpile\Exception\TranspileException;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/*
 * converts closure::call into another closure that dynamically checks
 * if bindTo needs to be called, or the regular call() is needed.
 *
 *
 *  $closure->call($three, 4);
 *
 * into:
 *
 *          echo call_user_func(function($arg1, $arg2) use ($closure) {
 *             if ($closure instanceOf Closure) {
 *                  $tmp = $closure->bindTo($arg1, get_class($arg1));
 *                  return $tmp($arg2);
 *              } else {
 *                  return $closure->call($arg1, $arg2);
 *              }
 *          }, $three, 4);
 */

class ClosureCallVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node)
    {
        // Trigger on function call "unserialize"
        if (!$node instanceof Node\Expr\MethodCall || $node->name != "call") {
            return null;
        }

/*
         echo call_user_func(function($a, $arg1) use ($c) {
         ��������if ($c instanceOf Closure) {
         ����������������$tmp = $c->bindTo($a, get_class($a));
         ����������������return $tmp($arg1);
         ��������} else {
         ����������������return $c->call($a, $arg1);
         ��������}
         }, $four, 3);
*/

        // in a "$closure::call",  $varName will be "closure"
        $varName = $node->var->name;

        // Set the correct number of params, naming them arg1..argN
        $params = array();
        $funcCallParams = array();
        for ($i=0; $i<count($node->args); $i++) {
            $params[] = new Node\Param('arg'.($i+1));
            $funcCallParams[] = new Node\Expr\Variable('arg'.($i+1));
        }

        // Remove the first argument from the argument list.
        array_shift($funcCallParams);


        $closureNode = new Node\Expr\Closure(array(
            'params' => $params,
            'uses' => array(
                new Node\Param($varName)
            ),
            'stmts' => array(
                new Node\Stmt\If_(
                    new Node\Expr\Instanceof_(new Node\Expr\Variable($varName), new Node\Name('Closure')),
                    array(
                        'stmts' => array(
                            new Node\Expr\Assign(
                                new Node\Expr\Variable('tmp'),
                                new Node\Expr\MethodCall(
                                    new Node\Expr\Variable($varName),
                                    'bindTo',
                                    array(
                                        new Node\Expr\Variable('arg1'),
                                        new Node\Expr\FuncCall(
                                            new Node\Name('get_class'),
                                            $funcCallParams
                                        )
                                    )
                                )
                            ),
                            new Node\Stmt\Return_(
                                new Node\Expr\FuncCall(
                                    new Node\Expr\Variable('tmp'),
                                    $funcCallParams
                                )
                            ),
                        ),
                        'else' => new Node\Stmt\Else_(array(
                            new Node\Stmt\Return_(
                                new Node\Expr\MethodCall(
                                    new Node\Expr\Variable($varName),
                                    'call',
                                    $funcCallParams
                                )
                            ),
                        )),
                    )
                ),
            )
        ));

        $args = $node->args;
        array_unshift($args, new Node\Arg($closureNode));

        $callUserFuncNode = new Node\Expr\FuncCall(
            new Node\Name('call_user_func'),
            $args
        );

        return $callUserFuncNode;
    }
}