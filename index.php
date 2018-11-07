<?php

require_once('vendor/autoload.php');
require_once('q-config.php');

use Michelf\Markdown;
use Michelf\MarkdownExtra;

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
ini_set('display_errors', 'On');

// -------------------------------------------------------------------------------------------------
// :: this will not work for characters that can't be match to latic like Chinese or Japanese
// -------------------------------------------------------------------------------------------------
function slug($title, $replace=array(), $delimiter='-') {
    $locale = setlocale(LC_ALL, 0);
    setlocale(LC_ALL, 'en_US.UTF8');
    if (!empty($replace)) {
        $str = str_replace((array)$replace, ' ', $title);
    }
    $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
    $clean = strtolower(trim($clean, '-'));
    $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
    setlocale(LC_ALL, $locale);
    return $clean;
}

// -------------------------------------------------------------------------------------------------
function redirect($request, $response, $uri) {
    if (is_string($uri)) {
        $url = baseURI($request) . $uri;
    } else if (get_class($uri) == "Slim\Http\Uri") {
        $url = url($uri);
    }
    return $response->withStatus(302)->withHeader('Location', $url);
}

// -------------------------------------------------------------------------------------------------
function baseURI($request) {
    $uri = $request->getUri();
    return $uri->getBasePath();
}

// -------------------------------------------------------------------------------------------------
function load_gettext_domains($root, $lang) {
    if (!preg_match("%" . DIRECTORY_SEPARATOR . "$%", $root)) {
        $root .= DIRECTORY_SEPARATOR;
    }
    $lang = preg_replace("/\.[^.]+$/", "", $lang);
    $path = $root . DIRECTORY_SEPARATOR .
            $lang . DIRECTORY_SEPARATOR . "LC_MESSAGES";
    if (file_exists($path)) {
        foreach (scandir($path) as $file) {
            if (preg_match("/(.*)\.mo$/", $file, $match)) {
                bindtextdomain($match[1], $root);
            }
        }
    }
}

// -------------------------------------------------------------------------------------------------
function compress($html) {
    $search = array(
        '/\n/',            // replace end of line by a space
        '/\>[^\S ]+/s',        // strip whitespaces after tags, except space
        '/[^\S ]+\</s',        // strip whitespaces before tags, except space
        '/(\s)+/s'        // shorten multiple whitespace sequences
    );
    $replace = array(
        '',
        '>',
        '<',
        '\\1'
    );
    $in_script = false;
    $array = preg_split("/(<\/?\s*script[^>]*>)/i", $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $outout = array();
    foreach ($array as $html) {
        if (preg_match("/^<\/?\s*script/i", $html)) {
            $in_script = !$in_script;
            $output[] = $html;
        } else if (!$in_script) {
            $output[] = preg_replace($search, $replace, $html);
        } else {
            $output[] = $html;
        }
    }
    return preg_replace('/(<!\s*DOCTYPE[^>]+>)/', "\\1\n", implode("", $output));
}

// -------------------------------------------------------------------------------------------------
function tidy($html, $options = array()) {
    $config = array_merge(array(
        'indent' => true,
        'input-xml' => true,
        'output-xhtml' => true,
        'wrap' => 200,
        'merge-spans' => false,
        'merge-divs' => false
    ), $options);
    $tidy = new tidy();
    $tidy->parseString($html, $config, 'utf8');
    $tidy->cleanRepair();
    return $tidy;
}

// -------------------------------------------------------------------------------------------------
function array_clean($array) {
    return array_filter(array_map('clean_input', $array), function($item) {
        return $item != '';
    });
}

// -------------------------------------------------------------------------------------------------
function clean_input($string) {
    return trim(strip_tags($string));
}

// -------------------------------------------------------------------------------------------------
function match($a, $b) {
    return count(array_diff($a, $b)) == 0 && count(array_diff($b, $a)) == 0;
}

// -------------------------------------------------------------------------------------------------
function array_pluck($array, $field) {
    return array_map(function($row) use ($field) {
        return $row[$field];
    }, $array);
}
/*
 * source: https://stackoverflow.com/a/18602474/387194
 * usage:
 * echo time_elapsed_string('2013-05-01 00:22:35');
 * echo time_elapsed_string('@1367367755'); # timestamp input
 * echo time_elapsed_string('2013-05-01 00:22:35', true);
 *
 * output:
 * 2 weeks ago
 * 2 weeks, 3 days, 1 hour, 49 minutes, 15 seconds ago
 *
 * (modification) it diff is more then one month it return translated date
 * funtion translate `%s ago` and textual representation of numerical values
 */

function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    if ($diff->m) {
        // we need to translate month in proper form
        $timestamp = $ago->getTimestamp();
        $locale = setlocale(LC_ALL, 0);
        setlocale(LC_ALL, 'en_US.UTF8');
        $moth = strftime("%B", $timestamp);
        if ($diff->y) {
            $date = strftime("%e %%s %y", $timestamp);
        }
        $date = strftime("%e %%s", $timestamp);
        setlocale(LC_ALL, $locale);
        return sprintf($date, _($moth));
    }
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . ngettext($v, $v . "s", (int)$diff->$k);
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? sprintf(_("%s ago"), implode(', ', $string)) : _('just now');
}

// -------------------------------------------------------------------------------------------------
function get_markdown_languages($text) {
    $langs = array();
    if (preg_match_all('%<pre><code class="([^" ]+)"%', $text, $matches)) {
        $langs = array_intersect(PROGRAMMING_LANGUAGES, $matches[1]);
    }
    return $langs;
}


// -------------------------------------------------------------------------------------------------
// :: clean html according to options
// :: it expract code snippets apply tidy and add back the snippets
// -------------------------------------------------------------------------------------------------
function clean_html($html) {
    if ($html != strip_tags($html)) {
        $tags = array('script');
        $re_array = array(
            "%(<pre><code[^>]*>.*?</code></pre>)%s",
            "%(<(" . implode("|", $tags) . ')[^>]*>.*?</\2>)%s'
        );
        $exceptions = array();
        $count = 0;
        foreach($re_array as $re) {
            if (preg_match_all($re, $html, $matches)) {
                $exceptions = array_merge($exceptions, $matches[1]);
                $html = preg_replace_callback($re, function($matches) use (&$count) {
                    return "<pre>__" . $count++ . "__</pre>";
                }, $html);
            }
        }
        if (COMPRESS) {
            if (TIDY) {
                $html = tidy($html, array('wrap' => -1, 'indent' => false));
            }
            $html = compress($html);
        } elseif (TIDY) {
            $html = tidy($html, TIDY_OPTIONS);
        }
        if (count($exceptions) > 0) {
            $re = "%<pre>__([0-9]+)__</pre>%s";
            $html = preg_replace_callback($re, function($m) use ($exceptions) {
                return $exceptions[intval($m[1])];
            }, $html);
        }
    }
    return $html;
}

function pre_lang($text) {
    return preg_replace('%(<pre><code class=")([^" ]+")%', '$1language-$2', $text);
}

// -------------------------------------------------------------------------------------------------
class QuatroError extends Exception {
}

// -------------------------------------------------------------------------------------------------
class Quatro {
    static $query_vote = "(SELECT count(*) FROM post_votes AS pv LEFT JOIN votes ON " .
                         "pv.id = connection WHERE up = true AND pv.id = post.votes) - (SELECT ".
                         "count(*) FROM post_votes AS pv LEFT JOIN votes ON pv.id = ".
                         "connection WHERE up = false AND pv.id = post.votes) as votes ";
    static $autor_query = " LEFT JOIN users u ON author = u.id ";
    static $tags_query = "SELECT name FROM tags LEFT JOIN question_tags qt ON tags.id = ".
                         "qt.tag_id WHERE question_id ";

    function __construct($lang = NULL) {
        header_remove("X-Powered-By");
        header("X-Frame-Options: Deny");
        header('X-Content-Type-Options: nosniff');
        session_name("QAID");
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        session_start();
        $container = new \Slim\Container;

        $this->db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                            DB_USER,
                            DB_PASSWORD);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // templates
        $this->root = __DIR__ . DIRECTORY_SEPARATOR;
        $templates_dir = $this->root . 'templates' . DIRECTORY_SEPARATOR;

        $this->config = new stdClass();
        if (count($this->query("SHOW TABLES LIKE 'config'")) == 1) {
            foreach ($this->query("SELECT name, value FROM config") as $options) {
                $name = $options['name'];
                $this->config->$name = $options['value'];
            }
        }

        if (isset($this->config->template) && $this->config->template != 'default') {
            $template = array(
                $templates_dir . $this->config->template,
                $templates_dir . 'default'
            );
        } else {
            $template = $templates_dir . 'default';
        }

        $this->vote_query = (isset($_SESSION['userid']) ? ("(SELECT up FROM post_votes AS pv LEFT " .
                                                           "JOIN votes ON pv.id = connection WHERE pv.id ".
                                                           "= post.votes AND voter = " . $_SESSION['userid'] .
                                                           ")") : "NULL") . " as vote ";

        $this->loader = new Twig_Loader_Filesystem($template);
        $this->twig = new Twig_Environment($this->loader);

        $app = $this;
        $container['errorHandler'] = $container['phpErrorHandler'] = function ($c) use ($app) {
            return function($request, $response, $exception) use ($c, $app) {
                $stack = $exception->getTraceAsString();
                $message = $exception->getMessage();
                $type = get_class($exception);
                $line = $exception->getLine();
                $file = $exception->getFile();
                return $c['response']->withStatus(500)
                                     ->withHeader('Content-Type', 'text/plain')
                                     ->write($message . "\n" . $stack);
            };
        };
        $container['settings']['displayErrorDetails'] = true;
        if (!preg_match("/utf-?8$/", $lang)) {
            $lang .= ".utf8";
        }
        clearstatcache();
        putenv("LC_ALL=$lang");
        setlocale(LC_ALL, $lang);
        load_gettext_domains($this->root . "locale", $lang);
        $domain = "default";
        textdomain($domain);
        $this->twig->addFunction(new Twig_Function('_', function($text) {
            return _($text);
        }));
        $this->twig->addFunction(new Twig_Function('_n', function($s, $p, $n) {
            return sprintf(ngettext($s, $p, $n), $n);
        }));

        $this->app = new \Slim\App($container);
    }
    // ---------------------------------------------------------------------------------------------
    function install() {
        $queries = array_clean(explode(";", file_get_contents("create.sql")));
        foreach ($queries as $query) {
            $this->query($query);
        }
        $accounts = array_pluck($this->query("SELECT name FROM account_types"), "name");
        $diff = array_diff(array("admin", "moderator", "user", "beginer", "anonymous"), $accounts);
        if (count($diff) > 0) {
            $query = "INSERT INTO account_types(name) VALUES('" . implode("'), ('", $diff) . "')";
            echo $this->query($query) . "\n";
        }
    }
    // ---------------------------------------------------------------------------------------------
    function register($username, $email, $password, $type) {
        $ret = $this->query("SELECT count(*) FROM users LEFT JOIN account_types AS a ON a.id = ".
                            "type WHERE username = ? OR (email = ? AND a.name <> 'anonymous')",
                            clean_input($username),
                            clean_input($email));
        if ($ret[0]['count(*)'] > 0) {
            throw new QuatroError("user already exists");
        }
        $password = password_hash($password, PASSWORD_BCRYPT);
        $this->query("INSERT INTO users(email, username, password, type) SELECT ?, ?, ?, id " .
                     "FROM account_types WHERE account_types.name = ?",
                     $email,
                     $username,
                     $password,
                     $type);
        return $this->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function create_tag($name, $description = NULL) {
        $name = clean_input($name);
        if ($description != NULL) {
            $description = clean_input($description);
        }
        if ($name != "") {
            $ret = $this->query("SELECT * FROM tags WHERE name = ?", $name);
            if (count($ret) == 0) {
                $this->query("INSERT INTO tags(name, description) VALUES(?, ?)", $name, $description);
            }
        }
    }
    // ---------------------------------------------------------------------------------------------
    function create_anon($username, $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        $this->query("INSERT INTO users values(username, email, type) SELECT ?, ?, id FROM ".
                     "account_types AS a WHERE a.name = 'anonymous'", $username, $email);
        return $this->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function get_user_id($username) {
        $ret = $this->query("SELECT users.id FROM users LEFT JOIN account_types AS a ON ".
                            "a.id = type WHERE a.name <> 'anonymous' AND username = ?", $username);
        if (count($ret) == 1) {
            return $ret[0]['id'];
        }
    }
    // ---------------------------------------------------------------------------------------------
    function user_vote($userid, $post_id, $table = 'questions') {
        $ret = $this->query("SELECT up, v.id as vote_id FROM votes v LEFT JOIN post_votes " .
                            "pv ON pv.id = connection LEFT JOIN  $table ON votes = pv.id WHERE " .
                            "$table.id = ?",
                            $post_id);
        if (count($ret) != 0) {
            return $ret[0];
        }
    }
    // ---------------------------------------------------------------------------------------------
    function vote($userid, $post_id, $vote, $table = 'questions') {
        $user_vote = $this->user_vote($userid, $post_id, $table);
        if ($user_vote) {
            if ($user_vote['up'] == $vote) {
                throw new QuatroError("You already voted");
            }
            $this->query("UPDATE votes SET up = ? WHERE id = ?", (int)$vote, $user_vote['vote_id']);
        } else {
            $this->query("INSERT INTO votes(voter, up, connection) SELECT ?, ?, votes FROM $table WHERE " .
                         "id = ?",
                         $userid,
                         (int)$vote,
                         $post_id);
        }
        $ret = $this->query("SELECT post.id, " . self::$query_vote . " FROM $table post WHERE id = ?", $post_id);
        return $ret[0]['votes'];
    }
    // ---------------------------------------------------------------------------------------------
    function reply($userid, $question_id, $text) {
        $text = clean_input($text);
        $this->query("INSERT INTO post_votes() VALUES()");
        $votes_id = $this->lastInsertId();
        $this->query("INSERT INTO answers(question_id, answer, date, author, votes) VALUES(" .
                     "?, ?, NOW(), ?, ?)",
                     $question_id,
                     $text,
                     $userid,
                     $votes_id);
        return $this->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function ask_question($userid, $title, $text, $tags = array()) {
        $app = $this;
        $tags = implode(",", array_map(function($tag) use ($app) {
            $app->create_tag($tag);
            return $this->db->quote($tag);
        }, array_clean($tags)));
        $title = clean_input($title);
        $slug = slug($title);
        // text should be markdown
        $text = clean_input($text);
        $tags = array_pluck($this->query("SELECT id, name FROM tags WHERE name in ($tags)"), "id");
        $this->query("INSERT INTO post_votes() VALUES()");
        $votes_id = $this->lastInsertId();
        $this->query("INSERT INTO questions(question, title, author, slug, votes, date) VALUES".
                    "(?, ?, ?, ?, ?, NOW())",
                     $text,
                     $title,
                     $userid,
                     $slug,
                     $votes_id);
        $quetion_id = $this->lastInsertId();
        foreach ($tags as $tag_id) {
            $this->query("INSERT INTO question_tags(question_id, tag_id) VALUES(?, ?)",
                         $quetion_id,
                         $tag_id);
        }
        return array(
            'id' => $quetion_id,
            'slug' => $slug
        );
    }
    // ---------------------------------------------------------------------------------------------
    function get_tags($question_id) {
        return array_pluck($this->query(self::$tags_query . " = ?", $question_id), 'name');
    }
    // ---------------------------------------------------------------------------------------------
    function get_questions_from_tag($tag, $page = 0, $limit = 10) {

        $questions = $this->query("SELECT post.id, title, " . self::$query_vote .
                                  ", question, date, " . $this->vote_query .
                                  " FROM questions post WHERE ? in (" .
                                  self::$tags_query . " = post.id)", $tag);
        foreach ($questions as &$question) {
            $question['tags'] = $this->get_tags($question['id']);
        }
        return $questions;
    }
    // ---------------------------------------------------------------------------------------------
    function get_question($id) {
        $query = "SELECT post.id, title, " . self::$query_vote . ", question, slug, date, " .
                       "UNIX_TIMESTAMP(date) as timestamp, MD5(email) as hash, " .
                       " username, u.id as user_id, " . $this->vote_query .
                       "FROM questions post " . self::$autor_query . " WHERE post.id = ?";
        $result = $this->query($query, $id);
        if (count($result) == 1) {
            $result = $result[0];
            $result['tags'] = $this->get_tags($result['id']);
            return $result;
        }
    }
    // ---------------------------------------------------------------------------------------------
    function get_replies($question_id) {
        return $this->query("SELECT post.id, answer, ". self::$query_vote . ", date, UNIX_TIMESTAMP(date) ".
                                                              " as timestamp, MD5(email) as hash, username, u.id " .
                                                              "as user_id, " . $this->vote_query .
                                                              " FROM answers post ".  self::$autor_query .
                                                              " WHERE question_id = ?",
                     (int)$question_id);
    }
    // ---------------------------------------------------------------------------------------------
    function login($user, $password) {
        $data = $this->query("SELECT password, name as role, u.id as id FROM users u LEFT JOIN " .
                             "account_types as a ON a.id = type WHERE username = ?", $user);
        if (count($data) == 1) {
            $data = $data[0];
            $role = $data['role'];
            $hash = $data['password'];
            if (password_verify($password, $hash)) {
                $_SESSION['user'] = $user;
                $_SESSION['userid'] = $data['id'];
                $_SESSION['role'] = $role;
            }
        }
    }
    // ---------------------------------------------------------------------------------------------
    function delete_question($question_id) {
        $votes = $this->query("SELECT votes FROM quetions WHERE id = ?", $question_id);
        if (count($votes) == 1) {
            $votes = $votes[0]['votes'];
            $this->query("DLETE FROM votes WHERE connection = ?", $votes);
            $this->query("DLETE FROM post_votes WHERE id = ?", $votes);
            $this->query("DELETE FROM answers WHERE question_id = ?", $question_id);
            $this->query("DELETE FROM question_tags WHERE question_id = ?", $question_id);
            $this->query("DELETE FROM questions WHERE id = ?", $question_id);
        }
    }
    // ---------------------------------------------------------------------------------------------
    function lastInsertId() {
        return $this->db->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function get_var($array) {
        return array_values($array[0])[0];
    }
    // ---------------------------------------------------------------------------------------------
    function query($query) {
        $args = func_get_args();
        array_shift($args);
        if (count($args) == 0) {
            $res = $this->db->query($query);
        } else {
            $res = $this->db->prepare($query);
            if ($res) {
                if (!$res->execute($args)) {
                    throw Exception("execute query failed");
                }
            } else {
                throw Exception("wrong query");
            }
        }
        if ($res) {
            if (preg_match("/^\s*INSERT|UPDATE|DELETE|ALTER|CREATE|DROP/i", $query)) {
                return $res->rowCount();
            } else {
                    return $res->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            throw new Exception("Query Error");
        }
    }
    function render($request, $page, $data = array()) {
        $uri = $request->getUri();
        $base = $uri->getBasePath();
        $path = preg_replace(
            "%" . __DIR__ . "%",
            "",
            $this->loader->getSourceContext($page)->getPath()
        );
        $path = preg_replace('%/' . $page . '$%', '', $path);
        $html = $this->twig->render($page, array_merge(array(
            'userid' => isset($_SESSION['userid']) ? $_SESSION['userid'] : null,
            "path" => $base . $path,
            "root" => $uri->getScheme() . "://" . $uri->getAuthority() . $base,
            "now" => date("Y-m-d H:i:s"),
        ), $data));
        return clean_html($html);
    }
    // ---------------------------------------------------------------------------------------------
    function __call($name, $args) {
        return call_user_func_array(array($this->app, $name), $args);
    }
}
// -------------------------------------------------------------------------------------------------

$app = new Quatro(LANG);

// -------------------------------------------------------------------------------------------------
$app->get('/', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'text/plain');
    if (isset($_SESSION['user'])) {
        $response->write($_SESSION['user'] . " " . $_SESSION['role'] . "\n");
    }
    $response->write("install: " . ($app->install() ? 'true' : 'false') . "\n");

    try {
        $app->register("admin", "admin@jcubic.pl", "some_password", "admin");
    } catch (QuatroError $e) {
        $response->write("Admin exists\n");
    }

    $app->login("admin", "some_password");

    $app->create_tag("javascript");
    $app->create_tag("css");
    $app->create_tag("php");

    $admin_id = $app->get_user_id("admin");
    $response->write("admin: $admin_id\n");
    //$id = $app->ask_question($admin_id, "Jak napisać kod", "foo", array("javascript", "css"));

    $question = $app->get_questions_from_tag("css");

    $response->write("\n" . _("week") . "\n");
    //$app->query("UPDATE questions SET slug = ? WHERE id = ?", slug($question[0]['title']), $question[0]['id']);

    try {
        $app->vote($admin_id, $question[0]['id'], false);
    } catch (QuatroError $e) {
        $response->write("Already Voted\n");
    }
    $response->write(json_encode($question, JSON_PRETTY_PRINT) . "\n");
    $response->write(time_ago("2018-10-10") . "\n");
    $response->write(gettext("week") . "\n");
    return $response;
});

// -------------------------------------------------------------------------------------------------
$app->get('/edit/q/{id}', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'application/json');
    $body = $response->getBody();
    if ($request->isPost()) {
        $post = $request->getParsedBody();
    }
});

// -------------------------------------------------------------------------------------------------
$app->get('/edit/a/{id}', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'application/json');
    $body = $response->getBody();
    if ($request->isPost()) {
        $post = $request->getParsedBody();
    }
});

// -------------------------------------------------------------------------------------------------
$app->get('/q/{id}/{slug}', function($request, $response, $args) use ($app) {
    $body = $response->getBody();
    $question = $app->get_question($args['id']);
    if ($question) {
        $url = "/q/" . $args['id'] . "/" . $question['slug'];
        // redirect to canonical url
        if ($args['slug'] != $question['slug']) {
            return redirect($request, $response, $url);
        }
        $content = MarkdownExtra::defaultTransform($question['question']);
        // extract code snippet languages
        $question['languages'] = get_markdown_languages($content);
        // fix lang class to be more semantic (required by PrimsJS)
        $question['question'] = pre_lang($content);
        $responses = $app->get_replies($args['id']);
        foreach($responses as &$response) {
            $content = MarkdownExtra::defaultTransform($response['answer']);
            $question['languages'] = array_merge($question['languages'], get_markdown_languages($content));
            $response['answer'] = pre_lang($content);
            $response['time_ago'] = sprintf(_("Answered %s"), time_ago('@' . $response['timestamp']));
        }
        $count = count($responses);
        $answers = array(
            'count' => sprintf(ngettext('%s Answer', '%s Answers', $count), $count),
            'list' => $responses
        );
        $body->write($app->render($request, "question.html", array_merge(array(
            'logged' => isset($_SESSION['userid']),
            'canonical' => $url,
            'time_ago' => sprintf(_("Asked %s"), time_ago('@' . $question['timestamp'])),
            'params' => array_clean($request->getQueryParams()),
            'answers' => $answers
        ), $question)));
        // debug
        $body->write("\n<!-- " . json_encode($question, JSON_PRETTY_PRINT) . "\n\n" .
                     setlocale(LC_ALL, 0) . "\n\n" .
                     json_encode(array_pluck($responses, 'vote'), JSON_PRETTY_PRINT) . "\n\n" .
                     getenv("LC_ALL") . _("Answer") . " -->");
        return $response;
    } else {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
});

// -------------------------------------------------------------------------------------------------
$app->map(['GET', 'POST'], '/' . _('ask'), function($request, $response) use ($app) {
    $body = $response->getBody();
    if ($request->isPost()) {
        $post = $request->getParsedBody();
        if (isset($post['title']) && isset($post['question'])) {
            if (isset($_SESSION['userid'])) {
                $userid = $_SESSION['userid'];
            }
            if (isset($post['tags']) && !empty($post['tags'])) {
                $tags = explode(",", $post['tags']);
            }
            $ret = $app->ask_question($userid, $post['title'], $post['question'], $tags);
            return redirect($request, $response, sprintf('/q/%s/%s', $ret['id'], $ret['slug']));
        }
    } else {
        $body->write($app->render($request, "ask.html"));
    }
});

// -------------------------------------------------------------------------------------------------
$app->post('/vote/{type}/{id}', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'application/json');
    $body = $response->getBody();
    $post = $request->getParsedBody();
    if (isset($_SESSION['user']) && in_array($args['type'], array('question', 'answer'))) {
        try {
            $table = $args['type'] . "s";
            $count = $app->vote($_SESSION['userid'], intval($args['id']), intval($post['vote']), $table);
            $result = array('success' => true, 'count' => $count);
        } catch (QuatroError $e) {
            $result = array('success' => false);
        }
    } else {
        $result = array('success' => false);
    }
    $body->write(json_encode($result));
    return $response;
});

// -------------------------------------------------------------------------------------------------
$app->post('/answer/{id}', function($request, $response, $args) use ($app) {
    if (isset($_SESSION['userid'])) {
        $post = $request->getParsedBody();
        $app->reply($_SESSION['userid'], (int)$args['id'], $post['answer']);
        if (preg_match("%/ask/[0-9]+/[^/]+$%", $post['question'])) {
            return redirect($request, $response, $post['question']);
        }
    }
});


// -------------------------------------------------------------------------------------------------
$app->get('/week/{n}', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'text/plain');
    $body = $response->getBody();
    $body->write(slug("Jak masz na imię") . "\n");
    $n = 5;
    $body->write("to było $n " . ngettext("week", "weeks", $n) . " temu\n");
    $body->write(ngettext("week", "weeks", (int)$args['n']) . "\n");
    $body->write(sprintf(ngettext("it was %s week ago", "it was %s weeks ago", $n), $n));
    $text = MarkdownExtra::defaultTransform("**Foo**\n\n```javascript\nfunction() {}\n```");


    $body->write($text);

    return $response;
});

$app->run();



?>
