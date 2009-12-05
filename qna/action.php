<?php

/**
 * Plugin QnA: Layout parser
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Mykola Ostrovskyy <spambox03@mail.ru>
 */

/* Must be run within Dokuwiki */
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'action.php');
require_once(DOKU_PLUGIN . 'qna/info.php');

class action_plugin_qna extends DokuWiki_Action_Plugin {

    const STATE_CLOSED   = 0;
    const STATE_QUESTION = 1;
    const STATE_ANSWER   = 2;

    private $blockState;
    private $currentBlock;
    private $currentSectionLevel;
    private $sectionEdit;

    /**
     * Return some info
     */
    public function getInfo() {
        return qna_getInfo('layout parser');
    }

    /**
     * Register callbacks
     */
    public function register(&$controller) {
        $controller->register_hook('PARSER_HANDLER_DONE', 'AFTER', $this, 'onParserHandlerDone');
    }

    /**
     *
     */
    public function onParserHandlerDone(&$event, $param) {
        $this->reset();
        $this->fixLayout($event);
    }

    /**
     * Reset internal state
     */
    private function reset() {
        $this->rewriter = new qna_instruction_rewriter();
        $this->blockState = self::STATE_CLOSED;
    }

    /**
     * Insert implicit instructions
     */
    private function fixLayout(&$event) {
        $instructions = count($event->data->calls);
        for ($i = 0; $i < $instructions; $i++) {
            $instruction =& $event->data->calls[$i];

            switch ($instruction[0]) {
                case 'section_close':
                case 'section_edit':
                case 'header':
                case 'section_open':
                    if ($this->blockState != self::STATE_CLOSED) {
                        $this->rewriter->insertBlockCall($i, 'close_block', 2);
                        $this->blockState = self::STATE_CLOSED;
                    }
                    break;

                case 'plugin':
                    if ($instruction[1][0] == 'qna_block') {
                        $this->handlePluginQna($i, $instruction[1][1]);
                    }
                    break;
            }
        }

        if ($this->blockState != self::STATE_CLOSED) {
            $this->rewriter->appendBlockCall('close_block', 2);
        }

        $this->rewriter->apply($event->data->calls);
    }

    /**
     * Insert implicit instructions
     */
    private function handlePluginQna($index, $data) {
        switch ($data[0]) {
            case 'open_question':
                if ($this->blockState != self::STATE_CLOSED) {
                    $this->rewriter->insertBlockCall($index, 'close_block', 2);
                }

                $this->rewriter->insertBlockCall($index, 'open_block');
                $this->blockState = self::STATE_QUESTION;
                break;

            case 'open_answer':
                switch ($this->blockState) {
                    case self::STATE_CLOSED:
                        $this->rewriter->delete($index);
                        break;

                    case self::STATE_QUESTION:
                    case self::STATE_ANSWER:
                        $this->rewriter->insertBlockCall($index, 'close_block');
                        $this->blockState = self::STATE_ANSWER;
                        break;
                }
                break;

            case 'close_block':
                switch ($this->blockState) {
                    case self::STATE_CLOSED:
                        $this->rewriter->delete($index);
                        break;

                    case self::STATE_QUESTION:
                    case self::STATE_ANSWER:
                        $this->rewriter->insertBlockCall($index, 'close_block');
                        $this->blockState = self::STATE_CLOSED;
                        break;
                }
                break;
        }
    }
}

class qna_instruction_rewriter {

    const DELETE = 1;
    const INSERT = 2;

    private $correction;

    /**
     *
     */
    public function __construct() {
        $this->correction = array();
    }

    /**
     *
     */
    public function delete($index) {
        $this->correction[$index][] = array(self::DELETE);
    }

    /**
     *
     */
    public function insertPluginCall($index, $name, $data, $state, $text = '') {
        $this->correction[$index][] = array(self::INSERT, array('plugin', array($name, $data, $state, $text)));
    }

    /**
     *
     */
    public function insertBlockCall($index, $data, $repeat = 1) {
        for ($i = 0; $i < $repeat; $i++) {
            $this->insertPluginCall($index, 'qna_block', array($data), DOKU_LEXER_SPECIAL);
        }
    }

    /**
     *
     */
    public function appendPluginCall($name, $data, $state, $text = '') {
        $this->correction[-1][] = array(self::INSERT, array('plugin', array($name, $data, $state, $text)));
    }

    /**
     *
     */
    public function appendBlockCall($data, $repeat = 1) {
        for ($i = 0; $i < $repeat; $i++) {
            $this->appendPluginCall('qna_block', array($data), DOKU_LEXER_SPECIAL);
        }
    }

    /**
     *
     */
    public function apply(&$instruction) {
        if (count($this->correction) > 0) {
            $index = $this->getCorrectionIndex();
            $corrections = count($index);
            $instructions = count($instruction);
            $output = array();

            for ($c = 0, $i = 0; $c < $corrections; $c++, $i++) {
                /* Copy all instructions, which are ahead of the next correction */
                for ( ; $i < $index[$c]; $i++) {
                    $output[] = $instruction[$i];
                }

                $this->applyCorrections($i, $instruction, $output);
            }

            /* Copy the rest of instructions after the last correction */
            for ( ; $i < $instructions; $i++) {
                $output[] = $instruction[$i];
            }

            /* Handle appends */
            if (array_key_exists(-1, $this->correction)) {
                $this->applyAppend($output);
            }

            $instruction = $output;
        }
    }

    /**
     * Sort corrections on instruction index, remove appends
     */
    private function getCorrectionIndex() {
        $result = array_keys($this->correction);
        asort($result);
        $result = array_values($result);

        /* Remove appends */
        if ($result[0] == -1) {
            array_shift($result);
        }

        return $result;
    }

    /**
     *
     */
    private function applyCorrections($index, $input, &$output) {
        $delete = false;
        $position = $input[$index][2];

        foreach ($this->correction[$index] as $correction) {
            switch ($correction[0]) {
                case self::DELETE:
                    $delete = true;
                    break;

                case self::INSERT:
                    $output[] = array($correction[1][0], $correction[1][1], $position);
                    break;
            }
        }

        if (!$delete) {
            $output[] = $input[$index];
        }
    }

    /**
     *
     */
    private function applyAppend(&$output) {
        $lastCall = end($output);
        $position = $lastCall[2];

        foreach ($this->correction[-1] as $correction) {
            switch ($correction[0]) {
                case self::INSERT:
                    $output[] = array($correction[1][0], $correction[1][1], $position);
                    break;
            }
        }
    }
}