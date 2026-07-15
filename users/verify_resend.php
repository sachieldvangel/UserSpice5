<?php
require_once '../users/init.php';
require_once $abs_us_root.$us_url_root.'users/includes/template/prep.php';

// Event hook for hashing of emails in the db (v5.3.8+)
$query = $db->query("SELECT * FROM email");
$results = $query->first();
$act = $results->email_act;
$msg = lang("ERR_EM_VER");

if ($act != 1) {
  usError($msg);
  Redirect::to($us_url_root.'index.php');
}

if ($user->isLoggedIn()) $user->logout();

$token = Input::get('csrf');
if (Input::exists()) {
    if (!Token::check($token)) {
        include($abs_us_root.$us_url_root.'usersc/scripts/token_error.php');
    }
}

$email_sent = FALSE;
$errors = array();

if (Input::exists('post')) {
    $email = Input::get('email');

    // Check rate limit before processing
    if (!checkRateLimit('email_verification', null, $email)) {
        $errors[] = getRateLimitErrorMessage('email_verification');
    } else {
    $hooks = getMyHooks(['page'=>'verifyResendSubmit']);
    includeHook($hooks, 'body');

    if (!isset($hookData['overrideEmailVerification'])) {
      $fuser = new User($email);
      $check = $db->query("SELECT id FROM users WHERE email = ? AND email_verified = 1", [$email])->count();
      $validate = new Validate();
      $validation = $validate->check($_POST, array(
          'email' => array(
              'display'     => lang("GEN_EMAIL"),
              'valid_email' => true,
              'required'    => true,
          ),
      ));
    } else {
      $fields = ["validation", "fuser", "check", "email"];
      foreach ($fields as $f) {
        if (isset($hookData[$f])) {
          $$f = $hookData[$f];
        }
      }
    }

    if ($validation->passed()) {
        // Only a real, still-unverified account gets a fresh verification email.
        // Every other case (no such account, or already verified) must return the
        // IDENTICAL response so this endpoint can't be used to enumerate accounts
        // or their verification status. Mirrors the generic pattern in
        // forgot_password.php.
        if ($fuser->exists() && $check == 0) {
          $vericode = randomstring(15);
          $vericode_expiry = date("Y-m-d H:i:s", strtotime("+$settings->join_vericode_expiry hours"));
          $db->update('users', $fuser->data()->id, ['vericode' => hashVericode($vericode), 'vericode_expiry' => $vericode_expiry]);
          $options = array(
              'fname'                => $fuser->data()->fname,
              'email'                => $email,
              'vericode'             => $vericode,
              'user_id'              => $fuser->data()->id,
              'join_vericode_expiry' => $settings->join_vericode_expiry
          );
          $encoded_email = rawurlencode($email);
          $subject = lang("EML_VER") . " @ " . date("Y-m-d H:i:s");
          $body = email_body('_email_template_verify.php', $options);
          $email_sent = email($email, $subject, $body);
          $es = json_encode($email_sent);
          logger($fuser->data()->id, "User", "Requested a new verification email. $es");
          handleAuthSuccess('email_verification', $fuser->data()->id, $email);
          if (!$email_sent) {
              $errors[] = lang("ERR_EMAIL");
          }
        } else {
            // Account doesn't exist, or is already verified: perform the same visible
            // work and show the identical generic success card without disclosing
            // which. sleep() normalizes timing against the real-send path above.
            sleep(2);
            logger("", "Email Verification", "Verification resend requested for " . $email);
            handleAuthFailure('email_verification', null, $email);
            $email_sent = true;
        }
    } else {
        $errors = $validation->errors();
    }
    } // end rate limit check
}



$verifyCardContent = ($email_sent)
  ? $abs_us_root.$us_url_root.'users/views/_verify_resend_success.php'
  : $abs_us_root.$us_url_root.'users/views/_verify_resend.php';
?>
<!-- Single Responsive Card for Email Verification -->
<div class="container mt-5">
  <div class="row justify-content-center">
    <div class="col-md-6 mb-3">
      <div class="card">
         <div class="card-header">
            Email Verification
         </div>
         <div class="card-body">
             <?php require $verifyCardContent; ?>
         </div>
      </div>
    </div>
  </div>
</div>
<?php require_once $abs_us_root.$us_url_root.'users/includes/html_footer.php'; ?>
