<?php

define('DB_USER', 'qa');
define('DB_PASSWORD', 'qa');
define('DB_NAME', 'qa');
define('DB_HOST', 'localhost');

define('LANG', 'en_US');

// remove unnsesesery whitespace from html
define('COMPRESS', false);
// cleanup html
define('TIDY', true);
// options to php tidy function
define('TIDY_OPTIONS', array());

// list of languages that should be inserted as languages to question template
// default template is using this list to highlight code snippets from questions and answers
// using PrismJS library
define('PROGRAMMING_LANGUAGES', array());

?>
