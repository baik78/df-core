<?php

namespace DreamFactory\Core\Scripting\Engines;

use Log;
use DreamFactory\Core\Scripting\BaseEngineAdapter;
use DreamFactory\Core\Contracts\ScriptingEngineInterface;
use DreamFactory\Core\Exceptions\InternalServerErrorException;

class Python extends BaseEngineAdapter implements ScriptingEngineInterface
{
    public static function startup($options = null)
    {
        // TODO: Change the autogenerated stub
    }

    public function executeString($script, $identifier, array &$data = [], array $engineArguments = [])
    {
        $data['__tag__'] = 'exposed_event';

        /** @noinspection PhpUnusedLocalVariableInspection */
        $platform = static::buildPlatformAccess($identifier);

        // todo Look for a better way!
        $runnerShell = $this->enrobeScript($script, $data, $platform);

        $output = null;
        $return = null;
        try {
            /** @noinspection PhpUnusedLocalVariableInspection */
            $result = exec($runnerShell, $output, $return);
        } catch (\Exception $ex) {
            $message = $ex->getMessage();
            Log::error($message = "Exception executing Python: $message");

            return null;
        }

        if ($return > 0) {
            throw new InternalServerErrorException('Python script returned with error code: ' . $return);
        }

        if (is_array($output)) {
            foreach ($output as $item) {
                if (is_string($item)) {
                    if ((strlen($item) > 10) && (false !== substr_compare($item, '{"request"', 0, 10))) {
                        $item =
                            str_replace(["'{", "}',", "'", "True", "False", ":None", ": None"],
                                ["{", "},", "\"", "true", "false", ":null", ": null"], $item);
                        $data = json_decode($item, true);
                    } else {
                        echo $item;
                    }
                }
            }
        } elseif (is_string($output)) {
            if ((strlen($output) > 10) && (0 === substr_compare($output, '{"request"', 0, 10))) {
                $output =
                    str_replace(["'{", "}',", "'", "True", "False", "None"], ["{", "},", "\"", "true", "false", "null"],
                        $output);
                $data = json_decode($output, true);
            } else {
                echo $output;
            }
        }

        return $data;
    }

    public function executeScript($path, $identifier, array &$data = [], array $engineArguments = [])
    {
        // TODO: Implement executeScript() method.
    }

    public static function shutdown()
    {
        // TODO: Change the autogenerated stub
    }

    protected function enrobeScript($script, array &$data = [], array $platform = [])
    {
        $jsonEvent = json_encode($data, JSON_UNESCAPED_SLASHES);
        $jsonEvent = str_replace(['null', 'true', 'false'], ['None', 'True', 'False'], $jsonEvent);
        $jsonPlatform = json_encode($platform, JSON_UNESCAPED_SLASHES);
        $jsonPlatform = str_replace(['null', 'true', 'false'], ['None', 'True', 'False'], $jsonPlatform);
        $scriptLines = explode("\n", $script);

        $enrobedScript = <<<python
event = $jsonEvent;
platform = $jsonPlatform;

try:
    def my_closure(event, platform):
python;
        foreach ($scriptLines as $sl) {
            $enrobedScript .= "\n        " . $sl;
        }

        $enrobedScript .= <<<python

    event['script_result'] =  my_closure(event, platform);
except Exception as e:
    event['script_result'] = {'error':str(e)};
    event['exception'] = str(e)

print event;
python;
        $enrobedScript = trim($enrobedScript);

        return "python -c " . escapeshellarg($enrobedScript);
    }
}