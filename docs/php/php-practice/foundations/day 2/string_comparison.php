<?php

$age = 47;


var_dump($age == 47); //true because it converts to int but unsage

var_dump($age === 47); //true
var_dump($age === "47"); // false

var_dump($age !==47); false;
var_dump($age !=="47"); //true