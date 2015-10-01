<?php

namespace SlowProg\Mailer;

use Closure;
use Phalcon\DiInterface;
use Swift_Mailer;
use Swift_Message;
use Jeremeamia\SuperClosure\SerializableClosure;
use Phalcon\DI\InjectionAwareInterface;
use Phalcon\Queue\Beanstalk;

/**
 * Class Mailer
 *
 * @package SlowProg\Mailer
 */
class Mailer implements InjectionAwareInterface
{
    /**
     * The view environment instance
     *
     * @var \Phalcon\Mvc\View
     */
    protected $view;

    /**
     * The Swift Mailer instance
     *
     * @var Swift_Mailer
     */
    protected $swift;

    /**
     * The global from email and name
     *
     * @var array
     */
    protected $from;

    /**
     * The Benastalk queue instance
     *
     * @var \Phalcon\Queue\Beanstalk
     */
    protected $queue;

    /**
     * @var DiInterface
     */
    protected $di;

    /**
     * Create a new Mailer instance
     *
     * @param \Phalcon\Mvc\View $view
     * @param Swift_Mailer      $swift
     */
    public function __construct($view, Swift_Mailer $swift)
    {
        $this->view = $view;
        $this->swift = $swift;
    }

    /**
     * Send a new message using a view
     *
     * @param string|array   $view
     * @param array          $data
     * @param Closure|string $callback
     *
     * @return int
     */
    public function sendView($view, array $data, $callback)
    {
        return $this->send($this->viewToBody($view, $data), $callback);
    }

    /**
     * Send a new message using a ready body
     *
     * @param string|array   $body
     * @param Closure|string $callback
     *
     * @return int
     */
    public function send($body, $callback)
    {
        $message = $this->createMessage($body, $callback);

        return $this->sendSwiftMessage($message->getSwiftMessage());
    }

    /**
     * Queue a new e-mail message for sending with view
     *
     * @param string|array    $view
     * @param array           $data
     * @param \Closure|string $callback
     * @param boolean         $render
     *
     * @return mixed
     */
    public function queueView($view, array $data, $callback)
    {
		$message = $this->createMessage($this->viewToBody($view, $data), $callback);
		
        return $this->queue->put(json_encode([
            'message' => serialize($message->getSwiftMessage()),
        ]));
    }

    /**
     * Queue a new e-mail message for sending
     *
     * @param string|array    $body
     * @param \Closure|string $callback
     *
     * @return mixed
     */
    public function queue($body, $callback)
    {
		$message = $this->createMessage($body, $callback);
        
        return $this->queue->put(json_encode([
            'message' => serialize($message->getSwiftMessage()),
        ]));
    }

    /**
     * Render view to ready body for sending
     *
     * @param string|array   $view
     * @param array          $data
     *
     * @return array
     */
    public function viewToBody($view, array $data)
    {
	    $result = [];
	    
        // First we need to parse the view, which could either be a string or an array
        // containing both an HTML and plain text versions of the view which should
        // be used when sending an e-mail. We will extract both of them out here.
        list($htmlView, $plainView) = $this->parseView($view);

        // Once we have retrieved the view content for the e-mail we will set the body
        // of this message using the HTML type, which will provide a simple wrapper
        // to creating view based emails that are able to receive arrays of data.
        
        if (isset($htmlView))
            $result['html'] = $this->render($htmlView, $data);

        if (isset($plainView))
            $result['plain'] = $this->render($plainView, $data);

        return $result;
    }

    /**
     * Parse the given view name or array
     *
     * @param string|array $view
     *
     * @return array
     */
    protected function parseView($view)
    {
        if (is_string($view)) return [$view, null];

        // If the given view is an array with numeric keys, we will just assume that
        // both a "pretty" and "plain" view were provided, so we will return this
        // array as is, since must should contain both views with numeric keys.
        if (is_array($view) && isset($view[0])) {
            return $view;
        }

        // If the view is an array, but doesn't contain numeric keys, we will assume
        // the the views are being explicitly specified and will extract them via
        // named keys instead, allowing the developers to use one or the other.
        elseif (is_array($view)) {
            return [
                array_get($view, 'html'),
                array_get($view, 'text'),
            ];
        }

        throw new \InvalidArgumentException("Invalid view.");
    }

    /**
     * Send a Swift Message instance
     *
     * @param Swift_Message $message
     *
     * @return int
     */
    public function sendSwiftMessage(Swift_Message $message)
    {
        return $this->swift->send($message);
    }

    /**
     * Call the provided message builder
     *
     * @param $callback
     * @param $message
     *
     * @return mixed
     * @throws \InvalidArgumentException
     */
    protected function callMessageBuilder($callback, $message)
    {
        if ($callback instanceof Closure) {
            return call_user_func($callback, $message);
        }

        throw new \InvalidArgumentException("Callback is not valid.");
    }

    /**
     * Create a new message instance
     *
     * @param string|array   $body
     * @param Closure|string $callback
     *
     * @return Message
     */
    protected function createMessage($body, $callback)
    {
        $message = new Message(new Swift_Message);

        // If a global from email has been specified we will set it on every message
        // instances so the developer does not have to repeat themselves every time
        // they create a new message. We will just go ahead and push the email.
        if (isset($this->from['email'])) {
            $message->from($this->from['email'], $this->from['name']);
        }
		
		$this->callMessageBuilder($callback, $message);
        
        list($html, $plain) = $this->parseView($body);
        
        if (isset($html))
            $message->setBody($html, 'text/html');

        if (isset($plain))
            $message->addPart($plain, 'text/plain');
            
        return $message;
    }

    /**
     * Render the given view
     *
     * @param string $view
     * @param array  $data
     *
     * @return string
     */
    protected function render($view, $data)
    {
        ob_start();
        $this->view->partial($view, $data);
        $content = ob_get_clean();

        return $content;
    }

    /**
     * Get the view environment instance
     *
     * @return \Phalcon\Mvc\View
     */
    public function getViewEnvironment()
    {
        return $this->view;
    }

    /**
     * Get the Swift Mailer instance
     *
     * @return Swift_Mailer
     */
    public function getSwiftMailer()
    {
        return $this->swift;
    }

    /**
     * Set the Swift Mailer instance
     *
     * @param Swift_Mailer $swift
     */
    public function setSwiftMailer($swift)
    {
        $this->swift = $swift;
    }

    /**
     * Set the Beanstalk queue instance
     *
     * @param \Phalcon\Queue\Beanstalk $queue
     *
     * @return self
     */
    public function setQueue(Beanstalk $queue)
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Handle queue piecemeal for periodic launch with cron 
     *
     * @param integer $limit
     */
    public function handleQueue($limit = 50)
    {
		while ((is_null($limit) || --$limit >= 0) && ($job = $this->queue->peekReady()) !== false) {
			
		    $data = json_decode($job->getBody(), true);
// 		    $segments = explode(':', $data['job']);

// 		    if (count($segments) !== 2) continue;
		    
		    call_user_func_array([$this, 'sendSwiftMessage'], [unserialize($data['message'])]);
		    
		    $job->delete();
		}
    }

    /**
     * Set the global from email and name
     *
     * @param string $email
     * @param string $name
     */
    public function alwaysFrom($email, $name = null)
    {
        $this->from = compact('email', 'name');
    }

    /**
     * Sets the dependency injector
     *
     * @param mixed $dependencyInjector
     */
    public function setDI(DiInterface $dependencyInjector)
    {
        $this->di = $dependencyInjector;
    }

    /**
     * Returns the internal dependency injector
     *
     * @return DiInterface
     */
    public function getDI()
    {
        return $this->di;
    }
}
