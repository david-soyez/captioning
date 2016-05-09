<?php

namespace Captioning\Format;

use Captioning\File;
define('SRT_STATE_SUBNUMBER', 0);
define('SRT_STATE_TIME',      1);
define('SRT_STATE_TEXT',      2);
define('SRT_STATE_BLANK',     3);

class SubripFile extends File
{
    const PATTERN =
    '/^
                   ### First subtitle ###
    [\p{C}]{0,3}                                    # BOM
    [\d]+                                           # Subtitle order.
    ((?:\r\n|\r|\n))                                # Line end.
    [\d]{1,2}:[\d]{1,2}:[\d]{1,2}(?:,[\d]{1,3})?    # Start time. Milliseconds or leading zeroes not required.
    [ ]-->[ ]                                       # Time delimiter.
    [\d]{1,2}:[\d]{1,2}:[\d]{1,2}(?:,[\d]{1,3})?    # End time. Milliseconds or leading zeroes not required.
    (?:\1[\S ]+)+                                   # Subtitle text.
                   ### Other subtitles ###
    (?:
        \1\1(?<=\r\n|\r|\n)[\d]+\1
        [\d]{1,2}:[\d]{1,2}:[\d]{1,2}(?:,[\d]{1,3})?
        [ ]-->[ ]
        [\d]{1,2}:[\d]{1,2}:[\d]{1,2}(?:,[\d]{1,3})?
        (?:\1[\S ]+)+
    )*
    \1?
    \s* # Allow trailing whitespace
    $/xu'
    ;

    private $defaultOptions = array('_stripTags' => false, '_stripBasic' => false, '_replacements' => false);

    private $options = array();

    public function __construct($_filename = null, $_encoding = null, $_useIconv = false)
    {
        parent::__construct($_filename, $_encoding, $_useIconv);
        $this->options = $this->defaultOptions;
    }

    public function parse()
    {
        $matches = $this->parseAlt();

        if (empty($matches)) {
            return null;
        }

        $this->setLineEnding("\n");

        $subtitleOrder = 1;
        $subtitleTime = '';

        foreach ($matches as $match) {
            $subtitle = $match;
            $timeline = explode(' --> ', $subtitle[1]);

            $subtitleTimeStart = $timeline[0];
            $subtitleTimeEnd = $timeline[1];
            $subtitleTimeStart = $this->cleanUpTimecode($subtitleTimeStart);
            $subtitleTimeEnd = $this->cleanUpTimecode($subtitleTimeEnd);

            if (
                !$this->validateTimelines($subtitleTime, $subtitleTimeStart, true) ||
                !$this->validateTimelines($subtitleTimeStart, $subtitleTimeEnd)
            ) {
                switch (true) {
                    case !$this->validateTimelines($subtitleTime, $subtitleTimeStart, true): $errorMsg = 'Staring time invalid: ' . $subtitleTimeStart; break;
                    case !$this->validateTimelines($subtitleTimeStart, $subtitleTimeEnd): $errorMsg = 'Ending time invalid: ' . $subtitleTimeEnd; break;
                }
                throw new \Exception($this->filename.' is not a proper .srt file. (' . $errorMsg . ')');
            }

            // if caption line is not empty
            if(isset($subtitle[2]) && trim($subtitle[2]) !== '') {
                $subtitleTime = $subtitleTimeEnd;
                $cue = new SubripCue($subtitleTimeStart, $subtitleTimeEnd, $subtitle[2]);
                $cue->setLineEnding($this->lineEnding);
                $this->addCue($cue);
            }
        }

        return $this;
    }

    public function build()
    {
        $this->buildPart(0, $this->getCuesCount() - 1);

        return $this;
    }

    public function buildPart($_from, $_to)
    {
        $this->sortCues();

        $i = 1;
        $buffer = "";
        if ($_from < 0 || $_from >= $this->getCuesCount()) {
            $_from = 0;
        }

        if ($_to < 0 || $_to >= $this->getCuesCount()) {
            $_to = $this->getCuesCount() - 1;
        }

        for ($j = $_from; $j <= $_to; $j++) {
            $cue = $this->getCue($j);
            $buffer .= $i.$this->lineEnding;
            $buffer .= $cue->getTimeCodeString().$this->lineEnding;
            $buffer .= $cue->getText(
                    $this->options['_stripTags'],
                    $this->options['_stripBasic'],
                    $this->options['_replacements']
                );
            $buffer .= $this->lineEnding;
            $buffer .= $this->lineEnding;
            $i++;
        }

        $this->fileContent = $buffer;

        return $this;
    }

    /**
     * @param array $options array('_stripTags' => false, '_stripBasic' => false, '_replacements' => false)
     * @return SubripFile
     * @throws \UnexpectedValueException
     */
    public function setOptions(array $options)
    {
        if (!$this->validateOptions($options)) {
            throw new \UnexpectedValueException('Options consists not allowed keys');
        }
        $this->options = array_merge($this->defaultOptions, $options);
        return $this;
    }

    /**
     * @return SubripFile
     */
    public function resetOptions()
    {
        $this->options = $this->defaultOptions;
        return $this;
    }

    /**
     * @param array $options
     * @return bool
     */
    private function validateOptions(array $options)
    {
        foreach (array_keys($options) as $key) {
            if (!array_key_exists($key, $this->defaultOptions)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $startTimeline
     * @param string $endTimeline
     * @param boolean $allowEqual
     * @return boolean
     */
    private function validateTimelines(& $startTimeline, & $endTimeline, $allowEqual = false)
    {
        $startDateTime = \DateTime::createFromFormat('H:i:s,u', $startTimeline);
        $endDateTime = \DateTime::createFromFormat('H:i:s,u', $endTimeline);

        // If DateTime objects are equals need check milliseconds precision.
        if ($startDateTime == $endDateTime) {
            $startSeconds = $startDateTime->getTimestamp();
            $endSeconds = $endDateTime->getTimestamp();

            $startMilliseconds = ($startSeconds * 1000) + (int)substr($startTimeline, 9);
            $endMilliseconds = ($endSeconds * 1000) + (int)substr($endTimeline, 9);

            if($startMilliseconds > $endMilliseconds) {
                $startTimeline = $endTimeline;
            }
            return true;
        }

        if($startTimeline > $endTimeline) {
            $startTimeline = $endTimeline;
        }
        return true;
    }

        /**
     * Add milliseconds and leading zeroes if they are missing
     *
     * @param $timecode
     *
     * @return mixed
     */
    private function cleanUpTimecode($timecode)
    {
        strpos($timecode, ',') ?: $timecode .= ',000';

        $patternNoLeadingZeroes = '/(?:(?<=\:)|^)\d(?=(:|,))/';

        return preg_replace_callback($patternNoLeadingZeroes, function($matches)
        {
            return sprintf('%02d', $matches[0]);
        }, $timecode);
    }

    private function fixEmptyLines() {
        $tempContent = "";
        $lines = explode("\n",$this->fileContent);
        $nextNotBlank = false;
        foreach($lines as $line) {
            $line = str_replace("\r","",$line);
            if(strpos($line,'-->')!== false) {
                // next line should not be blank
                $nextNotBlank = true;
                $tempContent .= $line."\n";
                continue;
            }
            if($nextNotBlank==true && trim($line) === '') {
                $line = " \n";
            }

            $nextNotBlank = false;
            $tempContent .= $line."\n";
        }

        $this->fileContent = $tempContent;
    }

    protected function parseAlt() {
        $lines = explode("\n",$this->fileContent);
        $subs    = array();
        $state   = SRT_STATE_SUBNUMBER;
        $subNum  = 0;
        $subText = '';
        $subTime = '';

        foreach($lines as $line) {
            switch($state) {
                case SRT_STATE_SUBNUMBER:
                    $subNum = trim($line);
                    $state  = SRT_STATE_TIME;
                    break;

                case SRT_STATE_TIME:
                    $subTime = trim($line);
                    if(strpos($subTime,'-->')===false) {
                        $state       = SRT_STATE_SUBNUMBER;
                        continue;
                    }
                    $state   = SRT_STATE_TEXT;
                    break;

                case SRT_STATE_TEXT:
                    if (trim($line) == '') {
                        $sub = array();
                        $sub[0] = $subNum;
                        $sub[1] = $subTime;
                        $sub[2] = $subText;
                        $subs[]      = $sub; 
                        $subText     = '';
                        $state       = SRT_STATE_SUBNUMBER;
                    } else {
                        $subText .= ' '.$line;
                    }
                    break;
            }
        }

        return $subs;
    }
}
