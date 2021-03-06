<?php

/**
,,
`""*3b..
     ""*3o.
         "33o.			                  			S. Alexandre M. Lemaire
           "*33o.                                   alemaire@circlical.com
              "333o.
                "3333bo...       ..o:
                  "33333333booocS333    ..    ,.
               ".    "*3333SP     V3o..o33. .333b
                "33o. .33333o. ...A33333333333333b
          ""bo.   "*33333333333333333333P*33333333:
             "33.    V333333333P"**""*"'   VP  * "l
               "333o.433333333X
                "*3333333333333AoA3o..oooooo..           .b
                       .X33333333333P""     ""*oo,,     ,3P
                      33P""V3333333:    .        ""*****"
                    .*"    A33333333o.4;      .
                         .oP""   "333333b.  .3;
                                  A3333333333P
                                  "  "33333P"
                                      33P*"
		                              .3"
                                     "


*/

namespace CirclicalTwigTrans\Model\Twig;

use CirclicalTwigTrans\Model\Twig\Parser\TransParser;
use Twig_Node_Expression_Constant;
use Twig_Node_Expression_Filter;
use Twig_Node_Expression_Name;
use Twig_Node_Expression_TempName;
use Twig_Node_Print;
use Twig_Node_SetTemp;
use Twig_Compiler;
use Twig_Node;
use Twig_NodeInterface;
use Twig_Node_Expression;
use CirclicalTwigTrans\Exception\BlankTranslationException;

class TransNode extends Twig_Node
{
    const TYPE_PLURAL = 'plural';
    const TYPE_COUNT = 'count';
    const TYPE_NAME = 'name';
    const TYPE_DATA = 'data';

    private $domain;

    public function __construct(Twig_NodeInterface $body, $domain, Twig_NodeInterface $plural = null, Twig_Node_Expression $count = null, Twig_NodeInterface $notes = null, $line_number, $tag = null)
    {
        parent::__construct(
            array(
                'count' => $count,
                'body' => $body,
                'plural' => $plural,
                'notes' => $notes,
            ),
            array(),
            $line_number,
            $tag
        );

        $this->domain = $domain;
    }

    /**
     * Returns the token parser instances to add to the existing list.
     *
     * @return array An array of Twig_TokenParserInterface or Twig_TokenParserBrokerInterface instances
     */
    public function getTokenParsers()
    {
        return array(new TransParser());
    }


    /**
     * Compiles the node to PHP.
     *
     * @param Twig_Compiler $compiler A Twig_Compiler instance
     */
    public function compile(Twig_Compiler $compiler)
    {
        $compiler->addDebugInfo($this);

        /**
         * @var Twig_Node $msg
         * @var TWig_Node $msg1
         */
        try {
            list($msg, $vars) = $this->compileString($this->getNode('body'));

            if (null !== $this->getNode(self::TYPE_PLURAL)) {
                list($msg1, $vars1) = $this->compileString($this->getNode(self::TYPE_PLURAL));
                $vars = array_merge($vars, $vars1);
            }
        } catch (BlankTranslationException $x) {
            throw new \Exception($x->getMessage() . ' at line ' . $x->getCode() . ' in ' . $compiler->getFilename());
        }


        $is_plural = null === $this->getNode(self::TYPE_PLURAL) ? false : true;
        if (!$this->domain)
            $function = $is_plural ? 'ngettext' : 'gettext';
        else
            $function = $is_plural ? 'dngettext' : 'dgettext';

        // handle notes
        if (null !== $notes = $this->getNode('notes')) {
            $message = trim($notes->getAttribute(self::TYPE_DATA));

            // line breaks are not allowed cause we want a single line comment
            $message = str_replace(array("\n", "\r"), " ", $message);
            $compiler->write("// notes: {$message}\n");
        }

        if ($vars) {
            $compiler->write('echo strtr(' . $function . '(');

            if ($this->domain) {
                $compiler->repr($this->domain);
                $compiler->raw(', ');
            }

            $compiler->subcompile($msg);

            if (null !== $this->getNode(self::TYPE_PLURAL)) {
                $compiler
                    ->raw(', ')
                    ->subcompile($msg1)
                    ->raw(', abs(')
                    ->subcompile($this->getNode(self::TYPE_COUNT))
                    ->raw(')');
            }

            $compiler->raw('), array(');

            foreach ($vars as $var) {
                if (self::TYPE_COUNT === $var->getAttribute(self::TYPE_NAME)) {
                    $compiler
                        ->string('%count%')
                        ->raw(' => abs(')
                        ->subcompile($this->getNode(self::TYPE_COUNT))
                        ->raw('), ');
                } else {
                    $compiler
                        ->string('%' . $var->getAttribute(self::TYPE_NAME) . '%')
                        ->raw(' => ')
                        ->subcompile($var)
                        ->raw(', ');
                }
            }

            $compiler->raw("));\n");

        } else {
            $compiler->write('echo ' . $function . '(');
            if ($this->domain) {
                $compiler->repr($this->domain);
                $compiler->raw(', ');
            }

            $compiler->subcompile($msg);

            if (null !== $this->getNode(self::TYPE_PLURAL)) {
                $compiler
                    ->raw(', ')
                    ->subcompile($msg1)
                    ->raw(', abs(')
                    ->subcompile($this->getNode(self::TYPE_COUNT))
                    ->raw(')');
            }

            $compiler->raw(");\n");
        }
    }

    /**
     * @param Twig_NodeInterface $body A Twig_NodeInterface instance
     * @return array
     * @throws BlankTranslationException
     */
    protected function compileString(Twig_NodeInterface $body)
    {

        if ($body instanceof Twig_Node_Expression_Name || $body instanceof Twig_Node_Expression_Constant || $body instanceof Twig_Node_Expression_TempName) {
            if( $body instanceof Twig_Node_Expression_Constant ){
                if( !trim($body->getAttribute('value')) ){
                    throw new BlankTranslationException("You are attempting to translate an empty string", $body->getLine());
                }
            }
            return array($body, array());
        }

        $vars = array();
        if (count($body)) {
            $msg = '';

            foreach ($body as $node) {
                if (get_class($node) === 'Twig_Node' && $node->getNode(0) instanceof Twig_Node_SetTemp) {
                    $node = $node->getNode(1);
                }

                if ($node instanceof Twig_Node_Print) {
                    $n = $node->getNode('expr');
                    while ($n instanceof Twig_Node_Expression_Filter) {
                        $n = $n->getNode('node');
                    }
                    $msg .= sprintf('%%%s%%', $n->getAttribute(self::TYPE_NAME));
                    $vars[] = new Twig_Node_Expression_Name($n->getAttribute(self::TYPE_NAME), $n->getLine());
                } else {
                    $msg .= $node->getAttribute(self::TYPE_DATA);
                }
            }
        } else {
            /** @var Twig_Node $body */
            if (!$body->hasAttribute(self::TYPE_DATA)) {
                throw new BlankTranslationException("You are attempting to translate a empty string", $body->getLine());
            }
            $msg = $body->getAttribute(self::TYPE_DATA);
        }

        if (!trim($msg)) {
            throw new BlankTranslationException("You are attempting to translate a blank string", $body->getLine());
        }

        return array(new Twig_Node(array(new Twig_Node_Expression_Constant(trim($msg), $body->getLine()))), $vars);
    }

}