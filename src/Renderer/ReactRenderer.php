<?php

namespace TektonLabs\ReactOnLaravel\Renderer;

use Nacmartin\PhpExecJs\PhpExecJs;
use Psr\Log\LoggerInterface;
use Limenius\ReactRenderer\Context\ContextProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Limenius\ReactRenderer\Renderer\AbstractReactRenderer;

class ReactRenderer extends AbstractReactRenderer
{
    /**
     * @var PhpExecJs
     */
    protected $phpExecJs;
    /**
     * @var string
     */
    protected $serverBundlePath;
    /**
     * @var bool
     */
    protected $needToSetContext = true;
    /**
     * @var bool
     */
    protected $failLoud;
    /**
     * @var cache
     */
    protected $cache;
    /**
     * @var cacheKey
     */
    protected $cacheKey;
    /**
     * PhpExecJsReactRenderer constructor.
     *
     * @param string                   $serverBundlePath
     * @param bool                     $failLoud
     * @param ContextProviderInterface $contextProvider
     * @param LoggerInterface          $logger
     */
    public function __construct(ContextProviderInterface $contextProvider, LoggerInterface $logger = null)
    {
        $this->serverBundlePath = config('react_on_laravel.server_bundle', public_path('js/vendor.js'));
        $this->vendorBundlePath = config('react_on_laravel.vendor_bundle', public_path('js/app.js'));  
        $this->manifestPath = config('react_on_laravel.manifest', public_path('js/manifest.js'));  
        $this->failLoud = config('react_on_laravel.fail_loud', false);
        $this->logger = $logger;
        $this->contextProvider = $contextProvider;
    }
    public function setCache(CacheItemPoolInterface $cache, $cacheKey)
    {
        $this->cache = $cache;
        $this->cacheKey = $cacheKey;
    }
    /**
     * @param PhpExecJs $phpExecJs
     */
    public function setPhpExecJs(PhpExecJs $phpExecJs)
    {
        $this->phpExecJs = $phpExecJs;
    }
    /**
     * @param string $serverBundlePath
     */
    public function setServerBundlePath($serverBundlePath)
    {
        $this->serverBundlePath = $serverBundlePath;
        $this->needToSetContext = true;
    }
    /**
     * @param string $componentName
     * @param string $propsString
     * @param string $uuid
     * @param array  $registeredStores
     * @param bool   $trace
     *
     * @return array
     */
    public function render($componentName, $propsString, $uuid, $registeredStores = array(), $trace)
    {
        $this->ensurePhpExecJsIsBuilt();

        $context = $this->consolePolyfill()."\n".$this->timerPolyfills($trace);

        if (config('react_on_laravel.extract', false)) {
            $context .= "\n".$this->loadVendorBundle();
        }

        $context .= "\n".$this->loadServerBundle();

        $this->phpExecJs->createContext($context);

        $result = json_decode($this->phpExecJs->evalJs($this->wrap($componentName, $propsString, $uuid, $registeredStores, $trace)), true);
        if ($result['hasErrors']) {
            $this->logErrors($result['consoleReplayScript']);
            if ($this->failLoud) {
                $this->throwError($result['consoleReplayScript'], $componentName);
            }
        }
        return [
            'evaluated' => $result['html'],
            'consoleReplay' => $result['consoleReplayScript'],
            'hasErrors' => $result['hasErrors']
        ];
    }
    protected function loadServerBundle()
    {
        if (!$serverBundle = @file_get_contents($this->serverBundlePath)) {
            throw new \RuntimeException('Server bundle not found in path: '.$this->serverBundlePath);
        }
        return $serverBundle;
    }
    protected function loadVendorBundle()
    {
        if (!$vendorBundle = @file_get_contents($this->vendorBundlePath)) {
            throw new \RuntimeException('Server bundle not found in path: '.$this->vendorBundlePath);
        }

        if (!$manifest = @file_get_contents($this->manifestPath)) {
            throw new \RuntimeException('Server bundle not found in path: '.$this->manifestPath);
        }

        return $manifest."\n".$vendorBundle;
    }
    protected function ensurePhpExecJsIsBuilt()
    {
        if (!$this->phpExecJs) {
            $this->phpExecJs = new PhpExecJs();
        }
    }
    /**
     * @param $trace
     * @return string
     */
    protected function timerPolyfills($trace)
    {
        $timerPolyfills = <<<JS
function getStackTrace () {
  var stack;
  try {
    throw new Error('');
  }
  catch (error) {
    stack = error.stack || '';
  }
  stack = stack.split('\\n').map(function (line) { return line.trim(); });
  return stack.splice(stack[0] == 'Error' ? 2 : 1);
}
function setInterval() {
  {$this->undefinedForPhpExecJsLogging('setInterval', $trace)}
}
function setTimeout() {
  {$this->undefinedForPhpExecJsLogging('setTimeout', $trace)}
}
function clearTimeout() {
  {$this->undefinedForPhpExecJsLogging('clearTimeout', $trace)}
}
JS;
        return $timerPolyfills;
    }
    /**
     * @param $functionName
     * @param $trace
     * @return string
     */
    protected function undefinedForPhpExecJsLogging($functionName, $trace)
    {
        $undefinedForPhpExecJsLogging = !$trace ? '' : <<<JS
console.error(
  '"$functionName" is not defined for phpexecjs. https://github.com/nacmartin/phpexecjs#why-cant-i-use-some-functions-like-settimeout. ' +
  'Note babel-polyfill may call this.'
);
console.error(getStackTrace().join('\\n'));
JS;
        return $undefinedForPhpExecJsLogging;
    }
}
