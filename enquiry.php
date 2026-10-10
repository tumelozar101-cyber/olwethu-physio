<?php
/**
 * OLPhysio website enquiry handler (xneelo hosting).
 *
 * Emails each enquiry to the OLPhysio mailbox. Nothing is saved to a file or database,
 * no PHP session is started and no cookie is set, as described in the Privacy Notice
 * (sections 4, 11, 12 and 21) and the technical configuration statement.
 * ID number, medical aid name and member number are only accepted when the visitor
 * chose "Medical aid", and they travel by email only.
 */

const TO_ADDRESS   = 'olwethu@olphysio.co.za';
const FROM_ADDRESS = 'olwethu@olphysio.co.za';   // must be an address on the olphysio.co.za domain
const TOWNS        = ['Welkom', 'Bloemfontein', 'Klerksdorp', 'Parys', 'Kroonstad', 'Elsewhere — tell us below'];

date_default_timezone_set('Africa/Johannesburg');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// Only accept submissions coming from the OLPhysio site itself.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host   = strtolower($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && strtolower(parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== $host) {
    reply(403, ['ok' => false, 'error' => 'Request refused.']);
}

// Hidden honeypot field: real visitors leave it empty, spam bots fill it in.
if (trim($_POST['website'] ?? '') !== '') {
    reply(200, ['ok' => true]);
}

/** Single line field: strip control characters (blocks email header injection) and trim. */
function line(string $key, int $max): string {
    $v = (string)($_POST[$key] ?? '');
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $v)), 0, $max);
}
/** Multi line field: keep line breaks, strip other control characters. */
function block(string $key, int $max): string {
    $v = str_replace(["\r\n", "\r"], "\n", (string)($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $v);
    return mb_substr(trim($v), 0, $max);
}

$name    = line('name', 100);
$phone   = line('phone', 20);
$payment = line('payment', 20);
$town    = line('town', 60);
$message = block('message', 2000);

$errors = [];
if ($name === '')                                         $errors[] = 'name';
if (!preg_match('/^[0-9+()\s-]{9,20}$/', $phone))         $errors[] = 'contact number';
if (!in_array($payment, ['private', 'medical-aid'], true)) $errors[] = 'payment option';
if (!in_array($town, TOWNS, true))                        $errors[] = 'town';
if ($errors) {
    reply(422, ['ok' => false, 'error' => 'Please check the ' . implode(', ', $errors) . '.']);
}

// Medical aid details are only taken when the visitor chose medical aid (data minimisation).
$idNumber = $medicalAid = $memberNumber = '';
if ($payment === 'medical-aid') {
    $idNumber     = line('id_number', 20);
    $medicalAid   = line('medical_aid', 80);
    $memberNumber = line('member_number', 40);
}

$paymentLabel = $payment === 'medical-aid' ? 'Medical aid' : 'Private / Cash';
$body  = "New enquiry from the OLPhysio website\n";
$body .= "=====================================\n\n";
$body .= "Name:            $name\n";
$body .= "Contact number:  $phone\n";
$body .= "Payment:         $paymentLabel\n";
if ($payment === 'medical-aid') {
    $body .= "ID number:       " . ($idNumber     !== '' ? $idNumber     : '(not provided)') . "\n";
    $body .= "Medical aid:     " . ($medicalAid   !== '' ? $medicalAid   : '(not provided)') . "\n";
    $body .= "Member number:   " . ($memberNumber !== '' ? $memberNumber : '(not provided)') . "\n";
}
$body .= "Town:            $town\n\n";
$body .= "Message:\n" . ($message !== '' ? $message : '(no message)') . "\n\n";
$body .= "-------------------------------------\n";
$body .= "Sent " . date('d/m/Y H:i') . " (SAST) from the enquiry form on olphysio.co.za.\n";
$body .= "This enquiry was not stored on the website.\n";

$subject  = '=?UTF-8?B?' . base64_encode("Website enquiry: $name ($paymentLabel)") . '?=';
$headers  = "From: OLPhysio Website <" . FROM_ADDRESS . ">\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: OLPhysio enquiry form";

$sent = mail(TO_ADDRESS, $subject, $body, $headers, '-f' . FROM_ADDRESS);

if (!$sent) {
    reply(500, ['ok' => false, 'error' => '']);
}
reply(200, ['ok' => true]);
