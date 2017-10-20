<?php
/**
 * @package    Selenium-server-standalone
 *
 * @copyright  Copyright (C) 2005 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Class to easily launch selenium with a correct browser driver
 *
 * @since  3.0.2
 */
class Selenium
{

    protected $testsPath;
    protected $port;
    protected $shutdownUrlFormat = 'http://localhost:%s/extra/LifecycleServlet?action=shutdown';
    protected $statusUrlFormat = 'http://localhost:%s/wd/hub/status';

    /**
     * Selenium constructor.
     *
     * @param   array $options - array(
     *                              'browser' => 'firefox|chrome|MicrosoftEdge|Internet Explorer',
     *                              'insider' => true|false,
     *                              'selenium_params' => array()
     *                          )
     * @param bool $registerShutdown - if true (default) will register selenium with LifecycleServlet allowing shutdown via HTTP request
     */
    public function __construct($options, $registerShutdown = true)
    {
        $this->testsPath = getcwd() . DIRECTORY_SEPARATOR;
        if (!isset($options['browser'])) {
            echo 'You need to specify a browser';
            exit(1);
        }

        $this->browser = $options['browser'];

        $this->isInsider = $options['insider'] ?? false;

        $this->seleniumParams = '';

        if (!isset($options['selenium_params']) || !is_array($options['selenium_params'])) {
            $options['selenium_params'] = array();
        }
        if ($registerShutdown) {
            $options['selenium_params']['role'] = 'node';
            $options['selenium_params']['servlet'] = 'org.openqa.grid.web.servlet.LifecycleServlet';
            $options['selenium_params']['registerCycle'] = '0';
            if (!isset($options['selenium_params']['port'])) {
                $options['selenium_params']['port'] = '4444';
            }
        }
        $this->port = $options['selenium_params']['port'];
        $this->seleniumParams = $this->implodeKeyValueParams($options['selenium_params']);
    }

    public function checkPortListening(): bool
    {
        $connection = @fsockopen('localhost', $this->port);

        if (is_resource($connection)) {
            fclose($connection);
            return true;
        }
        return false;
    }

    public function getServerStatusInfo()
    {
        try {
            return json_decode(file_get_contents(sprintf($this->statusUrlFormat, $this->port)), true);
        }
        catch (\Exception $e)
        {

        }
        return null;
    }

    public function tryShutdown(int $timeout = 10): bool
    {
        if($this->checkPortListening()) {
            if ($this->isReady()) {
                try {
                    file_get_contents(sprintf($this->shutdownUrlFormat, $this->port));
                    $this->waitForShutdown();
                } catch (\Exception $e) {
                    return false;
                }
            }
        }
        return true;
    }


    public function isReady()
    {
        try {
            $serverStatus = $this->getServerStatusInfo();
            if($serverStatus !== null && isset($serverStatus['value']))
            {
                return $serverStatus['value']['ready'] ?? false;
            }
            return false;
        }
        catch (\Exception $e) {}
        return false;
    }

    protected function implodeKeyValueParams($arr): string
    {
        return implode(' ', array_map(
            function ($v, $k) {
                if (!is_int($k)) {
                    return '-' . $k . ' ' . $v;
                }
                return '-' . $v;
            },
            $arr,
            array_keys($arr)
        ));
    }

    public function tryRunningIfNotReady(): bool
    {
        if($this->checkPortListening())
        {
            return $this->isReady();
        }
        try
        {
            $this->run();
        }
        catch (\Exception $e) {}
        return $this->isReady();
    }

    /**
     * Start selenium
     *
     * @return void
     * @throws \RuntimeException
     *
     * @since version
     */
    public function run()
    {
        if (!$this->isWindows()) {
            $command = $this->testsPath . 'vendor/bin/selenium-server-standalone ' . $this->getWebdriver() . ' ' . $this->seleniumParams . ' >> selenium.log 2>&1 &';
            print('executing: ' . $command . PHP_EOL);
            exec($command);
        } else {
            $command = 'START java.exe -jar ' . $this->getWebdriver() . $this->seleniumParams . ' ' . __DIR__ . '\bin\selenium-server-standalone.jar';
            print('executing: ' . $command . PHP_EOL);
            pclose(popen($command, 'r'));
        }
        $this->waitForReady();
    }

    protected function waitForReady(int $timeout = 10)
    {
        for ($i = 0; $i < $timeout; $i++) {
            sleep(1);
            if ($this->isReady()) {
                return;
            }
        }
        throw new \RuntimeException('Selenium process is not listening');
    }

    protected function waitForShutdown(int $timeout = 10)
    {
        for ($i = 0; $i < $timeout; $i++) {
            sleep(1);
            if (!$this->checkPortListening()) {
                return;
            }
        }
        throw new \RuntimeException('Selenium process is not listening');
    }

    /**
     * Detect the correct driver for selenium
     *
     * @return  string the webdriver string to use with selenium
     *
     * @since version
     */
    public function getWebdriver(): string
    {
        $browser = $this->browser;
        $config = parse_ini_file(__DIR__ . '/config.dist.ini', true);

        if (file_exists(__DIR__ . '/config.ini')) {
            $config = parse_ini_file(__DIR__ . '/config.ini', true);
        }

        if ($browser === 'chrome') {
            $driver['type'] = 'webdriver.chrome.driver';
        } elseif ($browser === 'firefox') {
            $driver['type'] = 'webdriver.gecko.driver';
        } elseif ($browser === 'MicrosoftEdge') {
            $driver['type'] = 'webdriver.edge.driver';
        } elseif ($browser === 'internet explorer') {
            $driver['type'] = 'webdriver.ie.driver';
        }

        // All the exceptions in the world...
        if ($browser === 'MicrosoftEdge' && $this->isInsider) {
            $driver['path'] = __DIR__ . '/' . $config['MicrosoftEdge']['windowsInsider'];
        } elseif (isset($config[$browser][$this->getOs()])) {
            $driver['path'] = __DIR__ . '/' . $config[$browser][$this->getOs()];
        } else {
            print('No driver for your browser. Check your browser configuration in config.ini' . PHP_EOL);

            // We can't do anything without a driver, exit
            exit(1);
        }

        return '-D' . implode('=', $driver);
    }

    /**
     * Return the os name
     *
     * @return string
     *
     * @since version
     */
    private function getOs(): string
    {
        if (stripos(PHP_OS, 'windows') !== false) {
            return 'windows';
        }
        if (stripos(PHP_OS, 'darwin') !== false) {
            return 'mac';
        }

        return 'linux';
    }

    /**
     * Check if local OS is Windows
     *
     * @return bool
     */
    private function isWindows(): bool
    {
        return 0 === stripos(PHP_OS, 'WIN');
    }
}
