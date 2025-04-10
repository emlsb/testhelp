#!/usr/bin/php -q
<?php

define('IN_SCRIPT',1);
define('HESK_PATH', dirname(dirname(dirname(__FILE__))) . '/');
define('NO_HTTP_HEADER',1);

require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
require(HESK_PATH . 'inc/oauth_functions.inc.php');
require(HESK_PATH . 'inc/mail/imap/HeskIMAP.php');

$set_priority = -1;

hesk_authorizeNonCLI();

if (empty($hesk_settings['imap'])) {
    die($hesklang['ifd']);
}

if (hesk_check_maintenance(false)) {
    $message = $hesk_settings['debug_mode'] ? $hesklang['mm1'] : '';
    die($message);
}

if ($hesk_settings['imap_job_wait']) {
    $job_file = HESK_PATH . $hesk_settings['cache_dir'] . '/__imap-' . sha1(__FILE__) . '.txt';

    if (file_exists($job_file)) {
        $last = intval(file_get_contents($job_file));
        if ($last + $hesk_settings['imap_job_wait'] * 60 > time()) {
            $message = $hesk_settings['debug_mode'] ? $hesklang['ifr'] : '';
            die($message);
        } else {
            file_put_contents($job_file, time());
        }
    } else {
        file_put_contents($job_file, time());
    }
}

require(HESK_PATH . 'inc/pipe_functions.inc.php');
define('HESK_IMAP', true);
hesk_dbConnect();
$hesk_settings['TEST-MODE'] = (hesk_GET('test_mode') == 1) ? true : false;

$imap = new HeskIMAP();
$imap->host = $hesk_settings['imap_host_name'];
$imap->port = $hesk_settings['imap_host_port'];
$imap->username = $hesk_settings['imap_user'];

if ($hesk_settings['imap_conn_type'] === 'basic') {
    $imap->password = hesk_htmlspecialchars_decode($hesk_settings['imap_password']);
    $imap->useOAuth = false;
} elseif ($hesk_settings['imap_conn_type'] === 'oauth') {
    $access_token = hesk_fetch_access_token($hesk_settings['imap_oauth_provider']);
    if (!$access_token) {
        echo "<pre>" . $hesklang['oauth_error_retrieve'] . "</pre>";
        if ($hesk_settings['imap_job_wait']) {
            unlink($job_file);
        }
        return null;
    }
    $imap->accessToken = $access_token;
    $imap->useOAuth = true;
    $imap->password = null;
}

$imap->readOnly = $hesk_settings['TEST-MODE'];
$imap->ignoreCertificateErrors = $hesk_settings['imap_noval_cert'];
$imap->disableGSSAPI = $hesk_settings['imap_disable_GSSAPI'];
$imap->connectTimeout = 15;
$imap->responseTimeout = 15;

if ($hesk_settings['imap_enc'] === 'ssl') {
    $imap->ssl = true;
    $imap->tls = false;
} elseif ($hesk_settings['imap_enc'] === 'tls') {
    $imap->ssl = false;
    $imap->tls = true;
} else {
    $imap->ssl = false;
    $imap->tls = false;
}

set_time_limit($imap->connectTimeout * 4);

if ($imap->login()) {
    echo $hesk_settings['debug_mode'] ? "<pre>Connected to the IMAP server &quot;" . $imap->host . ":" . $imap->port . "&quot;.</pre>\n" : '';

    if ($imap->hasUnseenMessages()) {
        $emails = $imap->getUnseenMessageIDs();
        $emails_found = count($emails);
        echo $hesk_settings['debug_mode'] ? "<pre>Unread messages found: $emails_found</pre>\n" : '';

        if ($hesk_settings['TEST-MODE']) {
            $imap->logout();
            echo $hesk_settings['debug_mode'] ? "<pre>TEST MODE, NO EMAILS PROCESSED\n\nDisconnected from the IMAP server.</pre>\n" : '';
            if ($hesk_settings['imap_job_wait']) {
                unlink($job_file);
            }
            return null;
        }

        $this_email = 0;

        if (function_exists('set_time_limit')) {
            $time_limit = $emails_found * 300;
            if ($time_limit < 1800) {
                $time_limit = 1800;
            } elseif ($time_limit > 3600) {
                $time_limit = 3600;
            }
            $time_limit = 3600;
            set_time_limit($time_limit);
            echo $hesk_settings['debug_mode'] ? "<pre>Time limit set to {$time_limit} seconds.</pre>\n" : '';
        }

        foreach ($emails as $email_number) {
            $this_email++;
            echo $hesk_settings['debug_mode'] ? "<pre>Parsing message $this_email of $emails_found.</pre>\n" : '';

            if (($results = parser()) === false) {
                echo $hesk_settings['debug_mode'] ? "<pre>Error parsing email, see debug log. Aborting fetching.</pre>\n" : '';
                break;
            }

            $category_id = detectCategory($results['subject'], $results['message']);

            if ($id = hesk_email2ticket($results, 2, $category_id, $set_priority)) {
                echo $hesk_settings['debug_mode'] ? "<pre>Ticket $id created/updated.</pre>\n" : '';
            } elseif (isset($hesk_settings['DEBUG_LOG']['PIPE'])) {
                echo "<pre>Ticket NOT inserted: " . $hesk_settings['DEBUG_LOG']['PIPE'] . "</pre>\n";
            }

            if (!$hesk_settings['imap_keep']) {
                $imap->delete($email_number);
            }

            echo $hesk_settings['debug_mode'] ? "<br /><br />\n\n" : '';
        }

        if (!$hesk_settings['imap_keep']) {
            $imap->expunge();
            echo $hesk_settings['debug_mode'] ? "<pre>Expunged mail folder.</pre>\n" : '';
        }
    } else {
        echo $hesk_settings['debug_mode'] ? "<pre>No unread messages found.</pre>\n" : '';
    }

    $imap->logout();
    echo $hesk_settings['debug_mode'] ? "<pre>Disconnected from the IMAP server.</pre>\n" : '';
} elseif (!$hesk_settings['debug_mode']) {
    echo "<p>Unable to connect to the IMAP server.</p>\n";
}

if ($errors = $imap->getErrors()) {
    if ($hesk_settings['debug_mode']) {
        foreach ($errors as $error) {
            echo "<pre>" . hesk_htmlspecialchars($error) . "</pre>\n";
        }
    } else {
        echo "<h2>An error occured.</h2><p>For details turn <b>Debug mode</b> ON in settings and run this script again.</p>\n";
    }
}

unset($imap);

if ($hesk_settings['imap_job_wait']) {
    unlink($job_file);
}

return NULL;

	function decodeEmailText($text) {
    if (mb_detect_encoding($text, 'UTF-8', true) === false) {
        $text = quoted_printable_decode($text);
    }
    return $text;
}

function detectCategory($subject, $message) {
    $keywords = [
        2 => ['сервер', 'серверы', 'серверу', 'сервером', 'сервере', 'пароль', 'пароли', 'паролю', 'паролем',
		'клавиатура', 'клавиатуры', 'клавиатуре', 'клавиатурой', 'клавиатуре',
    'мышка', 'мышки', 'мышке', 'мышкой', 'мышке', 'монитор', 'мониторы', 'монитору', 'монитором', 'мониторе',
	'пароле', 'вайфай', 'вайфаю', 'вайфаем', 'вайфае', 'звук', 'звуки', 'звуку', 'звуком', 'звуке', 'громкость', 'громкости', 'громкости', 
	'громкостью', 'громкости', 'впн', 'vpn', 'программа', 'программы', 'программе', 'программой', 'программе', 'ситилинк', 'ситилинки',
	'ситилинку', 'ситилинком', 'ситилинке', 'установить', 'установки', 'установке', 'установкой', 'установке', 'обновление', 'обновления', 
	'обновлению', 'обновлением', 'обновлении', 'ошибка', 'ошибки', 'ошибке', 'ошибкой', 'ошибке', 'сеть', 'сети', 'сети', 'сетью', 'сети',
	'антивирус', 'антивирусы', 'антивирусу', 'антивирусом', 'антивирусе', 'драйвер', 'драйверы', 'драйверу', 'драйвером', 'драйвере',
	'авторизация', 'авторизации', 'авторизации', 'авторизацией', 'авторизации', 'почта', 'почты', 'почте', 'почтой', 'почте', 'IP-адрес',
	'IP-адреса', 'IP-адресу', 'IP-адресом', 'IP-адресе', 'шифрование', 'шифрования', 'шифрованию', 'шифрованием', 'шифровании', 'интернет',
	'интернеты', 'интернету', 'интернетом', 'интернете', 'файл', 'файлы', 'файлу', 'файлом', 'файле', 'картридж', 'картриджи', 'картриджу',
	'картриджем', 'картридже', 'принтер', 'принтеры', 'принтеру', 'принтером', 'принтере', 'доступ', 'доступы', 'доступу', 'доступом', 'доступе',
	'компас', 'компасы', 'компасу', 'компасом', 'компасе', 'солид', 'солиды', 'солиду', 'солидом', 'солиде', 'эксель', 'эксели', 'экселю',
	'экселем', 'экселе', 'пдф', 'пдф', 'pdf','диск', 'диски', 'диску', 'диском', 'диске', 'записать', 'записи', 'записи', 'записью', 'записи',
	'телефон', 'телефоны', 'телефону', 'телефоном', 'телефоне', 'windows', 'винда', 'винды', 'винде', 'виндой', 'винде', 'виндус', 'виндусы',
	'виндусу', 'виндусом', 'виндусе', 'виндовс', 'виндовсы', 'виндовсу', 'виндовсом', 'виндовсе'],
        3 => ['мебель', 'мебели', 'мебели', 'мебелью', 'мебели',
    'кресло', 'кресла', 'креслу', 'креслом', 'кресле', 'стул', 'стулья', 'стулу', 'стулом', 'стуле',
    'стол', 'столы', 'столу', 'столом', 'столе',
    'петля', 'петли', 'петле', 'петлей', 'петле',
    'двери', 'дверей', 'дверям', 'дверями', 'дверях',
    'лифт', 'лифты', 'лифту', 'лифтом', 'лифте',
    'лифта', 'лифтов', 'лифту', 'лифтом', 'лифте',
    'дверь', 'двери', 'двери', 'дверью', 'двери',
    'окно', 'окна', 'окну', 'окном', 'окне',
    'кондиционер', 'кондиционеры', 'кондиционеру', 'кондиционером', 'кондиционере',
    'уборка', 'уборки', 'уборке', 'уборкой', 'уборке',
    'мусор', 'мусоры', 'мусору', 'мусором', 'мусоре',
    'светильник', 'светильники', 'светильнику', 'светильником', 'светильнике',
    'розетка', 'розетки', 'розетке', 'розеткой', 'розетке',
    'замок', 'замки', 'замку', 'замком', 'замке',
    'жалюзи', 'жалюзи', 'жалюзи', 'жалюзи', 'жалюзи',
    'ковер', 'ковры', 'ковру', 'ковром', 'ковре',
    'санузел', 'санузлы', 'санузлу', 'санузлом', 'санузле',
    'микроволновка', 'микроволновки', 'микроволновке', 'микроволновкой', 'микроволновке',
    'кулер', 'кулеры', 'кулеру', 'кулером', 'кулере',
    'полка', 'полки', 'полке', 'полкой', 'полке',
    'протекает', 'протекает', 'протекает', 'протекает', 'протекает',
    'парковка', 'парковки', 'парковке', 'парковкой', 'парковке',
    'машина', 'машины', 'машине', 'машиной', 'машине',
    'снег', 'снега', 'снегу', 'снегом', 'снеге',
    'свет', 'света', 'свету', 'светом', 'свете',
    'стена', 'стены', 'стене', 'стеной', 'стене',
    'электричество', 'электричества', 'электричеству', 'электричеством', 'электричестве',
    'вода', 'воды', 'воде', 'водой', 'воде',
    'лампа', 'лампы', 'лампе', 'лампой', 'лампе',
    'провод', 'провода', 'проводу', 'проводом', 'проводе',
    'ключ', 'ключи', 'ключу', 'ключом', 'ключе',
    'ключи', 'ключей', 'ключам', 'ключами', 'ключах',
    'лестница', 'лестницы', 'лестнице', 'лестницей', 'лестнице',
    'коридор', 'коридоры', 'коридору', 'коридором', 'коридоре',
    'доска', 'доски', 'доске', 'доской', 'доске','открутить','прикрутить','покрасить','заделать','воздух'],
    ];

    $subject = decodeEmailText($subject);
    $message = decodeEmailText($message);

    $full_text = mb_strtolower($subject . ' ' . $message, 'UTF-8');

    // Инициализация счетчиков
    $category_counts = array_fill_keys(array_keys($keywords), 0);

    // Подсчет совпадений для каждой категории
    foreach ($keywords as $category_id => $words) {
        foreach ($words as $word) {
            $lower_word = mb_strtolower($word, 'UTF-8');
            if (mb_substr_count($full_text, $lower_word) > 0) {
                $category_counts[$category_id] += mb_substr_count($full_text, $lower_word);
            }
        }
    }

    // Выбор категории с максимальным количеством совпадений
    arsort($category_counts);
    $max_count = max($category_counts);

    if ($max_count === 0) {
        return 1; // Категория по умолчанию
    }

    $max_categories = array_keys($category_counts, $max_count);

    // Если несколько категорий с одинаковым счетом, можно добавить приоритет
    return $max_categories[0];
}