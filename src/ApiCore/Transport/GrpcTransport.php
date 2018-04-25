<?php
/*
 * Copyright 2018, Google Inc.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Google\ApiCore\Transport;

use Exception;
use Google\ApiCore\ApiException;
use Google\ApiCore\BidiStream;
use Google\ApiCore\Call;
use Google\ApiCore\ClientStream;
use Google\ApiCore\GrpcSupportTrait;
use Google\ApiCore\ServerStream;
use Google\ApiCore\ServiceAddressTrait;
use Google\ApiCore\ValidationException;
use Google\ApiCore\ValidationTrait;
use Google\Rpc\Code;
use Grpc\BaseStub;
use Grpc\Channel;
use Grpc\ChannelCredentials;
use GuzzleHttp\Promise\Promise;

/**
 * A gRPC based transport implementation.
 */
class GrpcTransport extends BaseStub implements TransportInterface
{
    use ValidationTrait;
    use GrpcSupportTrait;
    use ServiceAddressTrait;

    /**
     * Builds a GrpcTransport.
     *
     * @param string $serviceAddress
     *        The address of the API remote host, for example "example.googleapis.com. May also
     *        include the port, for example "example.googleapis.com:443"
     * @param array $config {
     *    Config options used to construct the gRPC transport.
     *
     *    @type array $stubOpts Options used to construct the gRPC stub.
     *    @type Channel $channel Grpc channel to be used.
     * }
     * @return GrpcTransport
     * @throws ValidationException
     */
    public static function build($serviceAddress, array $config = [])
    {
        self::validateGrpcSupport();
        $config += [
            'stubOpts' => [],
            'channel'  => null,
        ];
        list($addr, $port) = self::normalizeServiceAddress($serviceAddress);
        $host = "$addr:$port";
        $stubOpts = $config['stubOpts'];
        // Set the required 'credentials' key in stubOpts if it is not already set. Use
        // array_key_exists because null is a valid value.
        if (!array_key_exists('credentials', $stubOpts)) {
            $stubOpts['credentials'] = ChannelCredentials::createSsl();
        }
        $channel = $config['channel'];
        if (!is_null($channel) && !($channel instanceof Channel)) {
            throw new ValidationException(
                "Channel argument to GrpcTransport must be of type \Grpc\Channel, " .
                "instead got: " . print_r($channel, true)
            );
        }
        try {
            return new GrpcTransport($host, $stubOpts, $channel);
        } catch (Exception $ex) {
            throw new ValidationException(
                "Failed to build GrpcTransport: " . $ex->getMessage(),
                $ex->getCode(),
                $ex
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function startBidiStreamingCall(Call $call, array $options)
    {
        return new BidiStream(
            $this->_bidiRequest(
                '/' . $call->getMethod(),
                [$call->getDecodeType(), 'decode'],
                isset($options['headers']) ? $options['headers'] : [],
                $this->getCallOptions($options)
            ),
            $call->getDescriptor()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startClientStreamingCall(Call $call, array $options)
    {
        return new ClientStream(
            $this->_clientStreamRequest(
                '/' . $call->getMethod(),
                [$call->getDecodeType(), 'decode'],
                isset($options['headers']) ? $options['headers'] : [],
                $this->getCallOptions($options)
            ),
            $call->getDescriptor()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startServerStreamingCall(Call $call, array $options)
    {
        $message = $call->getMessage();

        if (!$message) {
            throw new \InvalidArgumentException('A message is required for ServerStreaming calls.');
        }

        return new ServerStream(
            $this->_serverStreamRequest(
                '/' . $call->getMethod(),
                $message,
                [$call->getDecodeType(), 'decode'],
                isset($options['headers']) ? $options['headers'] : [],
                $this->getCallOptions($options)
            ),
            $call->getDescriptor()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startUnaryCall(Call $call, array $options)
    {
        $call = $this->_simpleRequest(
            '/' . $call->getMethod(),
            $call->getMessage(),
            [$call->getDecodeType(), 'decode'],
            isset($options['headers']) ? $options['headers'] : [],
            $this->getCallOptions($options)
        );

        $promise = new Promise(
            function () use ($call, &$promise) {
                list($response, $status) = $call->wait();

                if ($status->code == Code::OK) {
                    $promise->resolve($response);
                } else {
                    throw ApiException::createFromStdClass($status);
                }
            },
            [$call, 'cancel']
        );

        return $promise;
    }

    private function getCallOptions(array $options)
    {
        $callOptions = isset($options['transportOptions']['grpcOptions'])
            ? $options['transportOptions']['grpcOptions']
            : [];

        if (isset($options['authWrapper'])) {
            $callOptions['call_credentials_callback'] = $options['authWrapper']->getAuthorizationHeaderCallback();
        }

        if (isset($options['timeoutMillis'])) {
            $callOptions['timeout'] = $options['timeoutMillis'] * 1000;
        }

        return $callOptions;
    }
}
