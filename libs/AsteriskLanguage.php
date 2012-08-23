<?php

class AsteriskLanguage implements ObserverInterface
{
    protected $_log;
    protected $config;
    protected $langdir;

    protected $translate_mtime = 0;

    protected $translate;
    protected $en;

    /**
     * @param LoggerInterface   $log
     * @param SugarLanguageData $config
     * @param PosixEvent        $posix
     * @param null              $language_dir
     * @throws AsteriskException
     */
    public function __construct(LoggerInterface $log, SugarLanguageData $config, PosixEvent $posix, $language_dir = null) {
        $this->_log = $log;
        $this->config = $config;
        $this->langdir = $language_dir ? $language_dir : __DIR__.'/../language/';

        if (!$this->load_data($this->config->language)) {
            if (!$this->load_data('en_us')) {
                throw new AsteriskException("Can't load language files");
            }
            $this->translate_mtime = 0;
        }

        $posix->addObserver($this, 'SIGHUP');
    }

    protected function load_data($language) {
        $locale_path = $this->langdir . $language . '.lang.php';

        $locale_stat = @stat($locale_path);
        $mod_strings = null;

        if (file_exists($locale_path)) {
            $file = file_get_contents($locale_path);
            $file = preg_replace('/^.*?<\?(php)?/is', '', $file);
            @eval($file);
        }

        if (!is_array($mod_strings)) {
            $locale_stat = false;
        }

        if ($this->config->isChanged('language') ||
            !$this->translate_mtime ||
            ($locale_stat && $locale_stat['mtime'] != $this->translate_mtime)
        ) {
            if ($locale_stat === FALSE) {
                $this->log("Fail to load language {$language}", 'ERROR');
                return false;
            } else {
                $this->translate_mtime = $locale_stat['mtime'];
                $this->translate = $mod_strings;
                $this->log("Language {$language} loaded", 'DEBUG');
            }
        }

        $this->config->clearChanged();
        return true;
    }

    public function t($string) {
        if (isset($this->translate[$string])) {
            return $this->translate[$string];
        } else {
            $this->log('Translation of string "{$string}" not found for language {$this->config->language}', 'ERROR');
            return '';
        }
    }

    protected function log($message, $level='INFO') {
        $this->_log->log(get_class().': '.$message, $level);
    }

    public function notify(ObservableInterface $source, $eventType, &$params = null)
    {
        if ($source instanceof PosixEvent && $eventType == 'SIGHUP') {
            $this->load_data($this->config->language);
        }
    }
}
