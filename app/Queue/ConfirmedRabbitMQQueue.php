<?php

namespace App\Queue;

use PhpAmqpLib\Channel\AMQPChannel;
use RuntimeException;
use VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue;

class ConfirmedRabbitMQQueue extends RabbitMQQueue
{
    protected function createChannel(): AMQPChannel
    {
        $channel = parent::createChannel();
        // Confirm mode must be selected once per channel; selecting again resets client sequence numbers.
        // Reference: https://www.rabbitmq.com/docs/confirms
        $channel->confirm_select();
        $reject = function (): void {
            throw new RuntimeException('The broker did not accept the message.');
        };
        $channel->set_nack_handler($reject);
        $channel->set_return_listener($reject);

        return $channel;
    }

    protected function publishBasic($msg, $exchange = '', $destination = '', $mandatory = false, $immediate = false, $ticket = null): void
    {
        parent::publishBasic($msg, $exchange, $destination, $mandatory, $immediate, $ticket);
        $this->getChannel()->wait_for_pending_acks_returns(3);
    }
}
