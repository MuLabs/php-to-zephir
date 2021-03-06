<?php

namespace PhpToZephir;

use PhpParser\Parser;
use PhpToZephir\Converter\Converter;
use PhpToZephir\CodeCollector\CodeCollectorInterface;
use PhpToZephir\Render\RenderInterface;

class Engine
{
    /**
     * @var Parser
     */
    private $parser = null;
    /**
     * @var Converter
     */
    private $converter = null;
    /**
     * @var ClassCollector
     */
    private $classCollector = null;
    /**
     * @var Logger
     */
    private $logger = null;

    /**
     * @param Parser              $parser
     * @param Converter\Converter $converter
     * @param ClassCollector      $classCollector
     * @param Logger              $logger
     */
    public function __construct(Parser $parser, Converter $converter, ClassCollector $classCollector, Logger $logger)
    {
        $this->parser         = $parser;
        $this->converter      = $converter;
        $this->classCollector = $classCollector;
        $this->logger         = $logger;
    }

    public function convert(CodeCollectorInterface $codeCollector, $filterFileName = null)
    {
        $zephirCode = array();
        $classes    = array();

        $files = $codeCollector->getCode();
        $count = count($files);
        $codes = array();
        
        $this->logger->log('Collect class names');
        $progress = $this->logger->progress($count);

        foreach ($files as $fileName => $fileContent) {
            try {
            	$codes[$fileName]   = $this->parser->parse($fileContent);
                $classes[$fileName] = $this->classCollector->collect($codes[$fileName], $fileName);
            } catch (\Exception $e) {
                $this->logger->log(
                    sprintf(
                        '<error>Could not convert file'."\n".'"%s"'."\n".'cause : %s %s %s</error>'."\n",
                        $fileName,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    )
                );
            }
            $progress->advance();
        }

        $progress->finish();

        $this->logger->log("\nConvert php to zephir");
        $progress = $this->logger->progress(count($classes));

        foreach ($classes as $phpFile => $class) {
            if ($filterFileName !== null) {
                if (basename($phpFile, '.php') !== $filterFileName) {
                    continue;
                }
            }

            $phpCode   = $codes[$phpFile];
            $fileName  = basename($phpFile, '.php');
            try {
                $converted = $this->convertCode($phpCode, $this->classCollector, $phpFile, $classes);
                $converted['class'] = $class;
            } catch (\Exception $e) {
                $this->logger->log(
                    sprintf(
                        'Could not convert file "%s" cause : %s %s %s'."\n",
                        $phpFile,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    )
                );
                $progress->advance();
                continue;
            }

            $zephirCode[$phpFile] = array_merge(
                $converted,
                array(
                    'phpPath'   => substr($phpFile, 0, strrpos($phpFile, '/')),
                    'fileName'  => $fileName,
                    'fileDestination' => $converted['class'].'.zep',
                )
             );

            $zephirCode[$phpFile]['fileDestination'] = strtolower(str_replace('\\', '/', $zephirCode[$phpFile]['fileDestination']));

            foreach ($converted['additionalClass'] as $aditionalClass) {
                $zephirCode[$phpFile.$aditionalClass['name']] = array_merge(
                    array(
                        'fileName'  => $aditionalClass['name'],
                        'zephir' => $aditionalClass['code'],
                        'fileDestination' => strtolower(str_replace('\\', '/', $converted['namespace']).'/'.$aditionalClass['name']).'.zep',
                        'destination' => strtolower(str_replace('\\', '/', $converted['namespace']).'/'),
                    )
                );
            }
            $progress->advance();
        }

        $progress->finish();
        $this->logger->log("\n");
        
        return $zephirCode;
    }

    /**
     * @param string $phpCode
     *
     * @return string
     */
    private function convertCode($phpCode, ClassCollector $classCollector, $fileName = null, array $classes = array())
    {
        $converted = $this->converter->nodeToZephir($phpCode, $classCollector, $fileName, $classes);
        
        return array(
            'zephir'          => $converted['code'],
            'php'             => $phpCode,
            'namespace'       => $converted['namespace'],
            'destination'     => str_replace('\\', '/', $converted['namespace']).'/',
            'additionalClass' => $converted['additionalClass'],
        );
    }
}
