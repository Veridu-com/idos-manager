<?php
/*
 * Copyright (c) 2012-2016 Veridu Ltd <https://veridu.com>
 * All rights reserved.
 */
declare(strict_types=1);

namespace App\Async;

/**
 * Async Socket Stream
 */
class Stream {
    private $stream;
    private $errNum;
    private $errStr;
    private $dataSent = false;
    private $dataReceived = false;

    /**
     * Class constructor.
     *
     * @param string $host
     * @param int $timeout
     *
     * @return void
     */
    public function __construct(string $host, int $timeout = 10) {
        $this->stream = stream_socket_client(
            $host,
            $this->errNum,
            $this->errStr,
            $timeout,
            STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT
        );
    }

    /**
     * Class destructor.
     *
     * @return void
     */
    public function __destruct() {
        fclose($this->stream);
    }

    /**
     * Returns if the stream is open.
     *
     * @return bool
     */
    public function isOpen() : bool {
        return is_resource($this->stream);
    }

    /**
     * Returns stream's resource identifier.
     *
     * @return resource
     */
    public function getStream() {
        return $this->stream;
    }

    /**
     * Reads up to $numBytes bytes from stream.
     *
     * @param int $numBytes
     *
     * @return string
     */
    public function readFromStream(int $numBytes = 8192) : string {
        $this->dataReceived = true;
        return fread($this->stream, $numBytes);
    }

    /**
     * Writes data to stream.
     *
     * @param string $data
     *
     * @return int
     */
    public function writeToStream(string $data) : int {
        $this->dataSent = true;
        return fwrite($this->stream, $data);
    }

    /**
     * Returns if data has been read from stream.
     *
     * @return bool
     */
    public function dataReceived() : bool {
        return $this->dataReceived;
    }

    /**
     * Returns if data has been written to stream.
     *
     * @return bool
     */
    public function dataSent() : bool {
        return $this->dataSent;
    }

    /**
     * Returns the error code.
     *
     * @return int
     */
    public function getErrorNum() : int {
        return $this->errNum;
    }

    /**
     * Returns the error message.
     *
     * @return string
     */
    public function getErrorStr() : string {
        return $this->errStr;
    }
}
