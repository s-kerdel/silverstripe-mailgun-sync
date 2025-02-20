<?php
namespace NSWDPC\Messaging\Mailgun\Connector;

use Mailgun\Mailgun;
use NSWDPC\Messaging\Mailgun\Log;
use NSWDPC\Messaging\Mailgun\Connector\Event as EventConnector;
use Mailgun\Model\Message\ShowResponse;
use NSWDPC\Messaging\Mailgun\SendJob;
use NSWDPC\Messaging\Mailgun\MailgunEvent;
use NSWDPC\Messaging\Mailgun\MailgunMimeFile;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\File;
use SilverStripe\Security\Group;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Symbiote\QueuedJobs\DataObjects\QueuedJobDescriptor;
use Exception;
use DateTime;

/**
 * Bottles up common message related requeste to Mailgun via the mailgun-php API client
 */
class Message extends Base
{

    /**
     * Delay sending (via queued job)
     * @var float
     */
    protected $send_in_seconds = 0;

    /**
     * Accelerated Mobile Pages (AMP) HTML part
     * @var string
     */
    protected $amp_html = "";

    /**
     * Template options: name, verions, text (template, t:xxx)
     * @var array
     */
    protected $template = [];

    /**
     * Options (o:xxx)
     * @var array
     */
    protected $options = [];

    /**
     * Headers (h:X-xxx), the X is NOT auto-prefixed
     * @var array
     */
    protected $headers = [];

    /**
     * Variables (v:xxx)
     * @var array
     */
    protected $variables = [];


    /**
     * Recipient variables for batch sending (recipient-variables)
     * @var array
     */
    protected $recipient_variables = [];

    /**
     * Retrieve MIME encoded version of message
     */
    public function getMime(MailgunEvent $event)
    {
        $client = $this->getClient();
        if (empty($event->StorageURL)) {
            throw new Exception("No StorageURL found on MailgunEvent #{$event->ID}");
        }
        // Get the mime encoded message, by passing the Accept header
        $message = $client->messages()->show($event->StorageURL, true);
        return $message;
    }

    /**
     * Send a message with parameters
     * See: http://mailgun-documentation.readthedocs.io/en/latest/api-sending.html#sending
     * @return SendResponse|QueuedJobDescriptor|null
     * @param array $parameters an array of parameters for the Mailgun API
     * @param string $in a strtotime value added to 'now' being the time that the message will be sent via a queued job, if enabled
     */
    public function send($parameters)
    {

        // If configured and not already specified, set the Sender hader
        if ($this->alwaysSetSender() && !empty($parameters['from']) && empty($parameters['h:Sender'])) {
            $parameters['h:Sender'] = $parameters['from'];
            $parameters['h:X-Auto-SetSender'] = '1';
        }

        // unset Silverstripe/PHP headers from the message, as they leak information
        unset($parameters['h:X-SilverStripeMessageID']);
        unset($parameters['h:X-SilverStripeSite']);
        unset($parameters['h:X-PHP-Originating-Script']);

        // apply Mailgun testmode if it is enabled in configuration
        $this->applyTestMode($parameters);

        // Add in all custom email parameters
        $this->addCustomParameters($parameters);

        // if required, apply the default recipient
        // a default recipient can be applied if the message has no "To" parameter
        $this->applyDefaultRecipient($parameters);

        // apply the webhook_filter_variable, if webhooks are enabled
        if($this->getWebhooksEnabled() && ($variable = $this->getWebhookFilterVariable())) {
            $parameters["v:wfv"] = $variable;
        }

        // Send a message defined by the parameters provided
        return $this->sendMessage($parameters);

    }

    /**
     * Sends a message
     * @param array $parameters
     */
    protected function sendMessage(array $parameters) {

        /**
         * @var Mailgun\Mailgun
         */
        $client = $this->getClient();
        /**
         * @var string
         */
        $domain = $this->getApiDomain();

        // send options
        $send_via_job = $this->sendViaJob();
        $in = $this->getSendIn();// seconds
        switch ($send_via_job) {
            case 'yes':
                return $this->queueAndSend($domain, $parameters, $in);
                break;
            case 'when-attachments':
                if (!empty($parameters['attachment'])) {
                    return $this->queueAndSend($domain, $parameters, $in);
                    break;
                }
                // fallback to direct
                // no break
            case 'no':
            default:
                return $client->messages()->send($domain, $parameters);
                break;
        }
    }

    /**
     * Base64 encode attachments, primarily used to avoid attachment corruption issues when storing binary data in a queued job
     * @param array $parameters
     */
    public function encodeAttachments(&$parameters)
    {
        if (!empty($parameters['attachment']) && is_array($parameters['attachment'])) {
            foreach ($parameters['attachment'] as $k=>$attachment) {
                $parameters['attachment'][$k]['fileContent'] = base64_encode($attachment['fileContent']);
            }
        }
    }

    /**
     * Base64 decode attachments, for decoding attachments encoded with {@link self::encodeAttachments()}
     * @param array $parameters
     */
    public function decodeAttachments(&$parameters)
    {
        if (!empty($parameters['attachment']) && is_array($parameters['attachment'])) {
            foreach ($parameters['attachment'] as $k=>$attachment) {
                $parameters['attachment'][$k]['fileContent'] = base64_decode($attachment['fileContent']);
            }
        }
    }

    /**
     * Returns a DateTime being when the queued job should be started after
     * @returns DateTime
     * @param string $in See:http://php.net/manual/en/datetime.formats.relative.php
     */
    private function getSendDateTime($in)
    {
        $default_in = '1 minute';
        if ($in == '') {
            $in = $default_in;
        }

        try {
            $dt = new DateTime("now +{$in}");
        } catch (\Exception $e) {
            // if $in results in a non-valid time parameter to DateTime, use the default
            $dt = new DateTime("now +{$default_in}");
        }
        return $dt;
    }

    /**
     * Send via the queued job
     * @param string $domain the Mailgun API domain e.g sandboxXXXXXX.mailgun.org
     * @param array $parameters Mailgun API parameters
     * @param string $in
     * @return QueuedJobDescriptor|false
     */
    private function queueAndSend($domain, $parameters, $in)
    {
        $this->encodeAttachments($parameters);
        $start = $this->getSendDateTime($in);
        $job  = new SendJob($domain, $parameters);
        if($job_id = QueuedJobService::singleton()->queueJob($job, $start->format('Y-m-d H:i:s'))) {
            return QueuedJobDescriptor::get()->byId($job_id);
        }
        return false;
    }

    /**
     * Lookup all events for the submission linked to this event
     */
    public function isDelivered(MailgunEvent $event, $cleanup = true)
    {

        // Query will be for this MessageId and a delivered status
        if (empty($event->MessageId)) {
            throw new Exception("Tried to query a message based on MailgunEvent #{$event->ID} with no linked MessageId");
        }

        // poll for delivered events, MG stores them for up to 30 days
        $connector = new EventConnector();
        $timeframe = 'now -30 days';
        $begin = Base::DateTime($timeframe);

        $event_filter = MailgunEvent::DELIVERED;
        $resubmit = false;// no we don't want to resubmit
        $extra_params = [
            'limit' => 25,
            'message-id' => $event->MessageId,
            'recipient' => $event->Recipient,// match against the recipient of the event
        ];

        // calling pollEvents will store  matching local MailgunEvent record(s)
        $events = $connector->pollEvents($begin, $event_filter, $resubmit, $extra_params);

        $is_delivered = !empty($events);
        return $is_delivered;
    }

    /**
     * Trim < and > from message id
     * @return string
     * @param string
     */
    public static function cleanMessageId($message_id)
    {
        $message_id = trim($message_id, "<>");
        return $message_id;
    }

    /**
     * When sending via a queued job, this the start time of the job in the future (in seconds)
     * This is not the "o:deliverytime" option ("Messages can be scheduled for a maximum of 3 days in the future.")
     * To set "deliverytime" set it as an option to setOptions()
     */
    public function setSendIn(float $seconds) {
        $this->send_in_seconds = $seconds;
        return $this;
    }

    public function getSendIn() {
        return $this->send_in_seconds;
    }

    /**
     * @param array $recipient_variables where key is a plain recipient address
     *              and value is a dictionary with variables
     *              that can be referenced in the message body.
     */
    public function setRecipientVariables(array $recipient_variables) {
        $this->recipient_variables = $recipient_variables;
        return $this;
    }

    /**
     * @returns string|null
     */
    public function getRecipientVariables() {
        return $this->recipient_variables;
    }

    public function setAmpHtml(string $html) {
        $this->amp_html = $html;
        return $this;
    }

    public function getAmpHtml() {
        return $this->amp_html;
    }

    public function setTemplate($template, $version = "", $include_in_text = "") {
        if($template) {
            $this->template = [
                'template' => $template,
                'version' => $version,
                'text' => $include_in_text == "yes" ? "yes" : "",
            ];
        }
        return $this;
    }

    public function getTemplate() {
        return $this->template;
    }

    /**
     * Keys are not prefixed with "o:"
     */
    public function setOptions(array $options) {
        $this->options = $options;
        return $this;
    }

    public function getOptions() {
        return $this->options;
    }

    /**
     * Keys are not prefixed with "h:"
     */
    public function setCustomHeaders(array $headers) {
        $this->headers = $headers;
        return $this;
    }

    public function getCustomHeaders() {
        return $this->headers;
    }

    /**
     * Keys are not prefixed with "v:"
     */
    public function setVariables(array $variables) {
        $this->variables = $variables;
        return $this;
    }

    public function getVariables() {
        return $this->variables;
    }

    /**
     * Based on options set in {@link NSWDPC\Messaging\Mailgun\MailgunEmail} set Mailgun options, params, headers and variables
     * @param NSWDPC\Messaging\Mailgun\MailgunEmail $email
     * @param array $parameters
     */
    protected function addCustomParameters(&$parameters)
    {

        // VARIABLES
        $variables = $this->getVariables();
        foreach($variables as $k=>$v) {
            $parameters["v:{$k}"] = $v;
        }

        // OPTIONS
        $options = $this->getOptions();
        foreach($options as $k=>$v) {
            $parameters["o:{$k}"] = $v;
        }

        // TEMPLATE
        $template = $this->getTemplate();
        if(!empty($template['template'])) {
            $parameters["template"] = $template['template'];
            if(!empty($template['version'])) {
                $parameters["t:version"] = $template['version'];
            }
            if(isset($template['text']) && $template['text'] == "yes") {
                $parameters["t:text"] = $template['text'];
            }
        }

        // AMP HTML handling
        if($amp_html = $this->getAmpHtml()) {
            $parameters["amp-html"] = $amp_html;
        }

        // HEADERS
        $headers = $this->getCustomHeaders();
        foreach($headers as $k=>$v) {
            $parameters["h:{$k}"] = $v;
        }

        // RECIPIENT VARIABLES
        if($recipient_variables = $this->getRecipientVariables()) {
            $parameters["recipient-variables"] = json_encode($recipient_variables);
        }

    }

}
