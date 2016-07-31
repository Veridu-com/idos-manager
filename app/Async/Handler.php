<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types=1);

namespace App\Async;

/**
 * Async Stream Handler
 */
class Handler implements \Countable {
    private $storage = [];

    public function wait(array &$read, array &$write, int $timeout = 0) : bool {
        $streamList = [];
        $streamRead = [];
        $streamWrite = [];
        $except = null;
        foreach ($this->storage as $index => $streamObject) {
            $stream = $streamObject->getStream();
            $streamList[$index] = $stream;
            $streamRead[] = $stream;
            $streamWrite[] = $stream;
        }

        if (stream_select($streamRead, $streamWrite, $except, $timeout) === false) {
            return false;
        }

        $read = [];
        foreach ($streamRead as $stream) {
            $index = array_search($stream, $streamList);
            if ($index === false) {
                continue;
            }
            $read[] = $this->storage[$index];
        }

        $write = [];
        foreach ($streamWrite as $stream) {
            $index = array_search($stream, $streamList);
            if ($index === false) {
                continue;
            }
            $write[] = $this->storage[$index];
        }

        return true;
    }

    public function add(Stream $stream) : self {
        $this->storage[] = $stream;
        return $this;
    }

    public function del(Stream $stream) : self {
        $index = array_search($stream, $this->storage);
        if ($index !== false) {
            unset($this->storage[$index]);
        }
        return $this;
    }

    public function count() : int {
        return count($this->storage);
    }
}
