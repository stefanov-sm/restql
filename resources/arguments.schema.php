<?php
// Simple runtime arguments validation schema generator (CLI)
// S. Stefanov, oct 2018

if (PHP_SAPI !== 'cli')
{
  echo 'This is a CLI script'.PHP_EOL;
}
elseif (($argc < 2) || ($argc > 3))
{
  echo 'Syntax: php arguments.schema.php <config.json file> [<schema file>]'.PHP_EOL;
}
else
{
  $argsObjectSchema = (object)
  [
    '$schema' => 'http://json-schema.org/draft-07/schema#', 'title' => 'restql arguments validation schema',
    'type' => 'object', 'additionalProperties' => false
  ];
  @ $config = json_decode(file_get_contents($argv[1]));
  if (json_last_error() === JSON_ERROR_NONE)
  {
    $output = fopen(($argc === 3 ? $argv[2]: 'php://stdout'), 'w');
    $properties = (object)[];
    $required = [];
    foreach ((array) $config -> arguments as $argname => $argdef)
    {
      $argObjectSchema = (object)[];
      $argdef = (array)$argdef;
      if (array_key_exists('constant', $argdef)) continue;
      $type = $argdef['type'];
      $type = ($type === 'text') ? 'string': $type;
      $argObjectSchema -> type = $type;
      if (array_key_exists('pattern', $argdef))
      {
        $argObjectSchema -> pattern = preg_replace('/(^\\/)|(\\/[imsxeug]*$)/', '', $argdef['pattern']);
      }
      if (!array_key_exists('default', $argdef))
      {
        $required[] = $argname;
      }
      $properties -> $argname = $argObjectSchema;
    }
    $argsObjectSchema -> properties = $properties;
    $argsObjectSchema -> required = $required;
    fwrite($output, json_encode($argsObjectSchema, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    fclose($output);
  }
  else
  {
    echo 'Bad config.json file'.PHP_EOL;
  }
}
