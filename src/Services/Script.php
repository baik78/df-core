<?php
namespace DreamFactory\Core\Services;

use DreamFactory\Core\Contracts\ServiceResponseInterface;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Scripting\ScriptEngineManager;
use DreamFactory\Library\Utility\Enums\Verbs;
use \Log;

/**
 * Script
 * Scripting as a Service
 */
class Script extends BaseRestService
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var string $content Content of the script
     */
    protected $content;
    /**
     * @var array $engineConfig Configuration for the scripting engine used by the script
     */
    protected $engineConfig;
    /**
     * @var array $scriptConfig Configuration for the engine for this particular script
     */
    protected $scriptConfig;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new Script Service
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config'));
        Session::replaceLookups($config, true);

        if (null === ($this->content = ArrayUtils::get($config, 'content', null, true))) {
//            throw new \InvalidArgumentException('Script content can not be empty.');
        }

        if (null === ($this->engineConfig = ArrayUtils::get($config, 'engine', null, true))) {
            throw new \InvalidArgumentException('Script engine configuration can not be empty.');
        }

        $this->scriptConfig = ArrayUtils::clean(ArrayUtils::get($config, 'config', [], true));
    }

    /**
     * @return bool|mixed
     * @throws \DreamFactory\Core\Events\Exceptions\ScriptException
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected function processRequest()
    {
        //	Now all actions must be HTTP verbs
        if (!Verbs::contains($this->action)) {
            throw new BadRequestException('The action "' . $this->action . '" is not supported.');
        }

        $data =
            [
                'request'  => $this->request->toArray(),
                'response' => [
                    'content'      => null,
                    'content_type' => null,
                    'status_code'  => ServiceResponseInterface::HTTP_OK
                ],
                'resource' => $this->resourcePath
            ];

        $logOutput = $this->request->getParameterAsBool('log_output', true);
        $output = null;
        $result = ScriptEngineManager::runScript(
            $this->content,
            'script.' . $this->name,
            $this->engineConfig,
            $this->scriptConfig,
            $data,
            $output
        );

        if (!empty($output) && $logOutput) {
            \Log::info("Script '{$this->name}' output:" . PHP_EOL . $output . PHP_EOL);
        }

        //  Bail on errors...
        if (is_array($result) && isset($result['script_result'], $result['script_result']['error'])) {
            throw new InternalServerErrorException($result['script_result']['error']);
        }
        if (is_array($result) && isset($result['exception'])) {
            throw new InternalServerErrorException(ArrayUtils::get($result, 'exception', ''));
        }

        //  The script runner should return an array
        if (is_array($result) && isset($result['__tag__'])) {
            if (!empty($response = ArrayUtils::get($result, 'response', []))) {
                $content = ArrayUtils::get($response, 'content');
                $contentType = ArrayUtils::get($response, 'content_type');
                $status = ArrayUtils::get($response, 'status_code', ServiceResponseInterface::HTTP_OK);

//                $format = ArrayUtils::get($response, 'format', DataFormats::PHP_ARRAY);

                return ResponseFactory::create($content, $contentType, $status);
            }

            $scriptResult = ArrayUtils::get($result, 'script_result', []);

            return ResponseFactory::create($scriptResult);
        } else {
            Log::error('  * Script did not return an array: ' . print_r($result, true));
        }

        return ResponseFactory::create($output);
    }

    public static function getApiDocInfo(Service $service)
    {
        return ['paths' => [], 'definitions' => []];
    }
}
