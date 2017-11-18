#!/usr/bin/php
<?php
if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../') . '/');
define('NOSESSION', 1);
require_once(DOKU_INC . 'inc/init.php');

/**
 * Find wanted pages
 */
class WantedPagesCLI extends DokuCLI {

    const DIR_CONTINUE = 1;
    const DIR_NS = 2;
    const DIR_PAGE = 3;

    private $skip = false;
    private $sort = 'wanted';

    private $result = array();

    /**
     * Register options and arguments on the given $options object
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function setup(DokuCLI_Options $options) {
        $options->setHelp(
            'Outputs a list of wanted pages (pages that do not exist yet) and their origin pages ' .
            ' (the pages that are linkin to these missing pages).'
        );
        $options->registerArgument(
            'namespace',
            'The namespace to lookup. Defaults to root namespace',
            false
        );

        $options->registerOption(
            'sort',
            'Sort by wanted or origin page',
            's',
            '(wanted|origin)'
        );

        $options->registerOption(
            'skip',
            'Do not show the second dimension',
            'k'
        );
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function main(DokuCLI_Options $options) {

        if($options->args) {
            $startdir = dirname(wikiFN($options->args[0] . ':xxx'));
        } else {
            $startdir = dirname(wikiFN('xxx'));
        }

        $this->skip = $options->getOpt('skip');
        $this->sort = $options->getOpt('sort');

        $this->info("searching $startdir");

        foreach($this->get_pages($startdir) as $page) {
            $this->internal_links($page);
        }
        ksort($this->result);
        foreach($this->result as $main => $subs) {
            if($this->skip) {
                print "$main\n";
            } else {
                $subs = array_unique($subs);
                sort($subs);
                foreach($subs as $sub) {
                    printf("%-40s %s\n", $main, $sub);
                }
            }
        }
    }

    /**
     * Determine directions of the search loop
     *
     * @param string $entry
     * @param string $basepath
     * @return int
     */
    protected function dir_filter($entry, $basepath) {
        if($entry == '.' || $entry == '..') {
            return WantedPagesCLI::DIR_CONTINUE;
        }
        if(is_dir($basepath . '/' . $entry)) {
            if(strpos($entry, '_') === 0) {
                return WantedPagesCLI::DIR_CONTINUE;
            }
            return WantedPagesCLI::DIR_NS;
        }
        if(preg_match('/\.txt$/', $entry)) {
            return WantedPagesCLI::DIR_PAGE;
        }
        return WantedPagesCLI::DIR_CONTINUE;
    }

    /**
     * Collects recursively the pages in a namespace
     *
     * @param string $dir
     * @return array
     * @throws DokuCLI_Exception
     */
    protected function get_pages($dir) {
        static $trunclen = null;
        if(!$trunclen) {
            global $conf;
            $trunclen = strlen($conf['datadir'] . ':');
        }

        if(!is_dir($dir)) {
            throw new DokuCLI_Exception("Unable to read directory $dir");
        }

        $pages = array();
        $dh = opendir($dir);
        while(false !== ($entry = readdir($dh))) {
            $status = $this->dir_filter($entry, $dir);
            if($status == WantedPagesCLI::DIR_CONTINUE) {
                continue;
            } else if($status == WantedPagesCLI::DIR_NS) {
                $pages = array_merge($pages, $this->get_pages($dir . '/' . $entry));
            } else {
                $page = array(
                    'id' => pathID(substr($dir . '/' . $entry, $trunclen)),
                    'file' => $dir . '/' . $entry,
                );
                $pages[] = $page;
            }
        }
        closedir($dh);
        return $pages;
    }

    /**
     * Parse instructions and add the non-existing links to the result array
     *
     * @param array $page array with page id and file path
     */
    function internal_links($page) {
        global $conf;
        $instructions = p_get_instructions(file_get_contents($page['file']));
        $cns = getNS($page['id']);
        $exists = false;
        $pid = $page['id'];
        foreach($instructions as $ins) {
            if($ins[0] == 'internallink' || ($conf['camelcase'] && $ins[0] == 'camelcaselink')) {
                $mid = $ins[1][0];
                resolve_pageid($cns, $mid, $exists);
                if(!$exists) {
                    list($mid) = explode('#', $mid); //record pages without hashes

                    if($this->sort == 'origin') {
                        $this->result[$pid][] = $mid;
                    } else {
                        $this->result[$mid][] = $pid;
                    }
                }
            }
        }
    }
}

// Main
$cli = new WantedPagesCLI();
$cli->run();
