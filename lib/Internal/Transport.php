<?php

namespace Amp\Dns\Internal;

use Amp;
use Amp\Dns\DnsException;
use Amp\Dns\TimeoutException;
use Concurrent\Deferred;
use Concurrent\Task;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use function Amp\timeout;

/** @internal */
abstract class Transport
{
    private const MAX_CONCURRENT_REQUESTS = 500;

    /** @var array Contains already sent queries with no response yet. For UDP this is exactly zero or one item. */
    private $pending = [];

    /** @var MessageFactory */
    private $messageFactory;

    /** @var int Used for determining whether the socket can be garbage collected, because it's inactive. */
    private $lastActivity;

    /** @var bool */
    private $receiving = false;

    /** @var array Queued requests if the number of concurrent requests is too large. */
    private $queue = [];

    /**
     * @param string $uri
     *
     * @return Transport
     *
     * @throws DnsException
     */
    abstract public static function createFromUri(string $uri): Transport;

    /**
     * @param Message $message
     *
     * @throws DnsException
     */
    abstract protected function send(Message $message): void;

    /**
     * @return Message
     *
     * @throws DnsException
     */
    abstract protected function receive(): Message;

    /**
     * @return bool
     */
    abstract public function isAlive(): bool;

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    protected function __construct()
    {
        $this->messageFactory = new MessageFactory;
        $this->lastActivity = \time();
    }

    /**
     * @param Question $question
     * @param int      $timeout
     *
     * @return Message
     */
    public function ask(Question $question, int $timeout): Message
    {
        $this->lastActivity = \time();

        if (\count($this->pending) > self::MAX_CONCURRENT_REQUESTS) {
            $deferred = new Deferred;
            $this->queue[] = $deferred;
            Task::await($deferred->awaitable());
        }

        do {
            $id = \random_int(0, 0xffff);
        } while (isset($this->pending[$id]));

        $message = $this->createMessage($question, $id);

        try {
            $this->send($message);
        } catch (DnsException $exception) {
            $this->error($exception);

            throw $exception;
        }

        $deferred = new Deferred;
        $pending = new class
        {
            use Amp\Struct;

            public $deferred;
            public $question;
        };

        $pending->deferred = $deferred;
        $pending->question = $question;
        $this->pending[$id] = $pending;

        if (!$this->receiving) {
            $this->receiving = true;
            Amp\rethrow(Task::async(\Closure::fromCallable([$this, 'receiveIncomingMessages'])));
        }

        try {
            return Task::await(timeout($deferred->awaitable(), $timeout));
        } catch (Amp\TimeoutException $exception) {
            unset($this->pending[$id]);

            throw new TimeoutException("Didn't receive a response for '{$question->getName()}' within {$timeout} milliseconds.");
        } finally {
            if ($this->queue) {
                $deferred = array_shift($this->queue);
                $deferred->resolve();
            }
        }
    }

    abstract public function close(): void;

    private function error(\Throwable $exception): void
    {
        $this->close();

        if (empty($this->pending)) {
            return;
        }

        if (!$exception instanceof DnsException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new DnsException($message, 0, $exception);
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $pendingQuestion) {
            /** @var Deferred $deferred */
            $deferred = $pendingQuestion->deferred;
            $deferred->fail($exception);
        }
    }

    /**
     * @throws DnsException
     */
    abstract protected function read(): ?string;

    /**
     * @param string $data
     *
     * @throws DnsException
     */
    abstract protected function write(string $data): void;

    protected function createMessage(Question $question, int $id): Message
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($id);

        return $request;
    }

    private function receiveIncomingMessages(): void
    {
        $this->lastActivity = \time();

        while ($this->receiving) {
            try {
                $message = $this->receive();
            } catch (\Throwable $exception) {
                $this->error($exception);
                return;
            }

            $id = $message->getID();

            // Ignore duplicate and invalid responses.
            if (isset($this->pending[$id]) && $this->matchesQuestion($message, $this->pending[$id]->question)) {
                /** @var Deferred $deferred */
                $deferred = $this->pending[$id]->deferred;
                unset($this->pending[$id]);
                $deferred->resolve($message);
            }

            if (empty($this->pending)) {
                $this->receiving = false;
            }
        }
    }

    private function matchesQuestion(Message $message, Question $question): bool
    {
        if ($message->getType() !== MessageTypes::RESPONSE) {
            return false;
        }

        $questionRecords = $message->getQuestionRecords();

        // We only ever ask one question at a time
        if (\count($questionRecords) !== 1) {
            return false;
        }

        $questionRecord = $questionRecords->getIterator()->current();

        if ($questionRecord->getClass() !== $question->getClass()) {
            return false;
        }

        if ($questionRecord->getType() !== $question->getType()) {
            return false;
        }

        if ($questionRecord->getName()->getValue() !== $question->getName()->getValue()) {
            return false;
        }

        return true;
    }
}
