<?php

/**
 * Columns Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Mykola Ostrovskyy <spambox03@mail.ru>
 *             Based on plugin by Michael Arlt <michael.arlt [at] sk-schwanstetten [dot] de>
 */

/* Must be run within Dokuwiki */
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'syntax.php');

class syntax_plugin_columns extends DokuWiki_Syntax_Plugin {

    var $mode;
    var $block;
    var $nextBlock;
    var $nestingLevel;
    var $nestedBlock;
    var $columns;
    var $align;

    /**
     * Constructor
     */
    function syntax_plugin_columns() {
        $this->mode = substr(get_class($this), 7);
        $this->block = 0;
        $this->nextBlock = 0;
        $this->nestingLevel = 0;
        $this->nestedBlock = array(0);
        $this->columns = array();
    }

    /**
     * Return some info
     */
    function getInfo() {
        return array(
            'author' => 'Mykola Ostrovskyy',
            'email'  => 'spambox03@mail.ru',
            'date'   => '2009-01-31',
            'name'   => 'Columns Plugin',
            'desc'   => 'Arrange information in multiple columns',
            'url'    => 'http://wiki.splitbrain.org/plugin:columns',
        );
    }

    /**
     * What kind of syntax are we?
     */
    function getType() {
        return 'container';
    }

    function getPType() {
        return 'block';
    }

    /**
     * What modes are allowed within our mode?
     */
    function accepts($mode) {
        /* Ensure that our own mode can be nested */
        if ($mode == $this->mode) {
            return true;
        }
        return parent::accepts($mode);
    }

    /**
     * What modes are allowed within our mode?
     */
    function getAllowedTypes() {
        return array (
            'container',
            'substition',
            'protected',
            'disabled',
            'formatting',
            'paragraphs'
        );
    }

    /**
     * Where to sort in?
     */
    function getSort() {
        return 65;
    }

    function connectTo($mode) {
        $columnsTag = $this->_getColumnsTagName();
        $this->Lexer->addEntryPattern('<' . $columnsTag . '.*?>(?=.*?</' . $columnsTag . '>)', $mode, $this->mode);
        $this->Lexer->addPattern($this->_getNewColumnTag(), $this->mode);
    }

    function postConnect() {
        $this->Lexer->addExitPattern('</' . $this->_getColumnsTagName() . '>', $this->mode);
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        switch ($state) {
            case DOKU_LEXER_ENTER:
                return $this->_handleEnter($match);

            case DOKU_LEXER_MATCHED:
                return $this->_handleMatched();

            case DOKU_LEXER_UNMATCHED:
                return array($state, $match);

            case DOKU_LEXER_EXIT:
                return $this->_handleExit();
        }
        return false;
    }

    /**
     * Create output
     */
    function render($mode, &$renderer, $data) {
        if ($mode == 'xhtml') {
            switch ($data[0]) {
                case DOKU_LEXER_ENTER:
                    $this->_renderEnter($renderer, $data[1], $data[2]);
                    break;

                case DOKU_LEXER_MATCHED:
                    $this->_renderMatched($renderer, $data[1], $data[2]);
                    break;

                case DOKU_LEXER_UNMATCHED:
                    $renderer->doc .= $renderer->_xmlEntities($data[1]);
                    break;

                case DOKU_LEXER_EXIT:
                    $renderer->doc .= '</td></tr></table>';
                    break;
            }
            return true;
        }
        elseif ($mode == 'metadata') {
            switch ($data[0]) {
                case DOKU_LEXER_EXIT:
                    $renderer->meta['columns'][$data[1]] = $data[2];
                    break;
            }
            return true;
        }
        return false;
    }

    /**
     */
    function _handleEnter($match) {
        $this->block = ++$this->nextBlock;
        $this->nestedBlock[++$this->nestingLevel] = $this->block;
        $this->columns[$this->block] = 1;

        $width['table'] = '-';
        $width['col'] = array();

        preg_match('/<' . $this->_getColumnsTagName() . '(.*?)>/', $match, $match);

        if (array_key_exists(1, $match)) {
            $temp = preg_split('/\s+/', $match[1], -1, PREG_SPLIT_NO_EMPTY);

            if (count($temp) > 0) {
                $width['table'] = array_shift($temp);
                $width['col'] = $temp;
            }
        }
        return array(DOKU_LEXER_ENTER, $this->block, $width);
    }

    /**
     */
    function _handleMatched() {
        $this->columns[$this->block]++;
        return array(DOKU_LEXER_MATCHED, $this->block, $this->columns[$this->block]);
    }

    /**
     */
    function _handleExit() {
        $result = array(DOKU_LEXER_EXIT, $this->block, $this->columns[$this->block]);
        $this->block = $this->nestedBlock[--$this->nestingLevel];
        return $result;
    }

    /**
     * Returns columns tag without the brackets
     */
    function _getColumnsTagName() {
        $tag = $this->getConf('kwcolumns');
        if ($tag == '') {
            $tag = $this->getLang('kwcolumns');
        }
        return $tag;
    }

    /**
     * Returns new column tag
     */
    function _getNewColumnTag() {
        $tag = $this->getConf('kwnewcol');
        if ($tag == '') {
            $tag = $this->getLang('kwnewcol');
        }
        if ($this->getConf('wrapnewcol') == 1) {
            $tag = '<' . $tag . '>';
        }
        return $tag;
    }

    /**
     * Renders table and col tags, starts the table with first column
     */
    function _renderEnter(&$renderer, $block, $width) {
        $renderer->doc .= $this->_renderTable($width['table']);

        $columns = $this->_getColumns($block);
        $colWidth = $width['col'];

        if (count($colWidth) < $columns) {
            $colWidth = array_pad($colWidth, $columns, '-');
        }

        $column = 0;
        $this->align = array();

        foreach ($colWidth as $width) {
            $this->align[++$column] = $this->_getAlignment($width);
            $renderer->doc .= $this->_renderCol(trim($width, '*'));
        }

        $renderer->doc .= '<tr>' . $this->_renderTd($this->align[1], 'first');
    }

    /**
     */
    function _renderMatched(&$renderer, $block, $column) {
        $html = '</td>';

        $class = '';
        if ($column == $this->_getColumns($block)) {
            $class = 'last';
        }

        $html .= $this->_renderTd($this->align[$column], $class);

        /* HACK: remove extra paragrap tags around new column tags */
        if (strstr(substr($renderer->doc, -5), '<p>') !== false) {
            $renderer->doc .= '</p>' . $html . '<p>';
        }
        else {
            $renderer->doc .= $html;
        }
    }

    /**
     * Returns number of columns in specified block
     */
    function _getColumns($block) {
        if (empty($this->columns)) {
            global $ID;

            $this->columns = p_get_metadata($ID, 'columns');
        }
        return $this->columns[$block];
    }

    /**
     * Returns column text alignment
     */
    function _getAlignment($width) {
        preg_match('/^(\*?).*?(\*?)$/', $width, $match);
        $align = $match[1] . '-' . $match[2];
        switch ($align) {
            case '-':
                return '';

            case '-*':
                return 'left';

            case '*-':
                return 'right';

            case '*-*':
                return 'center';
        }
    }

    /**
     */
    function _renderTable($width) {
        if ($width == '-') {
            return '<table class="columns-plugin">';
        }
        else {
            return '<table class="columns-plugin" style="width:' . $width . '">';
        }
    }

    /**
     */
    function _renderCol($width) {
        if ($width == '-') {
            return '<col>';
        }
        else {
            return '<col style="width:' . $width . '">';
        }
    }

    /**
     */
    function _renderTd($align, $class = '') {
        if ($align != '') {
            if ($class != '') {
                $class .= ' ';
            }
            $class .= $align;
        }
        if ($class == '') {
            $html = '<td';
        }
        else {
            $html = '<td class="' . $class . '"';
        }
        return $html . '>';
    }
}
