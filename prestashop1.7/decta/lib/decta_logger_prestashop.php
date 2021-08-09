<?php

Class DectaLoggerPrestashop {
    public function __construct($enabled = true) {
        $this->enabled = $enabled;
    }

    public function log($message) {
        if ($this->enabled) {
            Logger::addLog($message);
        }
    }
}