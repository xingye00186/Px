<?php

function main(): void
{
    $db='a';
    $db();

    $db='b';

    $db();

    run('a');
    run('b');
   $cls=new A;

   $b='cla_a';
   $cls->$b();
   $a='A';
   $cls=new $a;
    var_dump(PHP_VERSION);
    var_dump(php_uname());
    global $argv;
    var_dump($argv);
}

function run($func='a'){
    return $func();
}

class A{
    public function cla_a(){
        echo 'cla_a'.PHP_EOL;
    }

      public function cla_b(){
        echo 'cla_a'.PHP_EOL;
    }
}


function a(){
echo 'aaaa'.PHP_EOL;
}

function b(){
echo 'bbbb'.PHP_EOL;
}