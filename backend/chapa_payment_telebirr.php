<?php
// Enable strict type checking for better code quality
declare(strict_types=1);

// Include database configuration and helper functions
require_once __DIR__ . '/config.php';

// Initialize database connection
$pdo = db();
// Determine the action to perform (GET/POST parameter)
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

// Generic HTTP request function for Chapa API integration
function chapa_request(string $method, string $url, array $payload = [], array $headers = []): array {
  $ch = curl_init($url);
  // Configure cURL options for API requests
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_USERAGENT => 'HouseRentalPlatform/1.0',
  ]);

  // Handle payload encoding based on content type
  if (!empty($payload)) {
    $hasJsonHeader = false;
    foreach ($headers as $h) {
      if (stripos($h, 'application/json') !== false) $hasJsonHeader = true;
    }
    if ($hasJsonHeader) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } else {
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    }
  }

  // Execute request and capture response
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  // Handle request errors and response validation
  if ($resp === false) {
    return ['ok' => false, 'error' => $err ?: 'Chapa request failed', 'http_status' => $status];
  }
  $decoded = json_decode((string)$resp, true);
  if (!is_array($decoded)) {
    return ['ok' => false, 'error' => 'Invalid JSON from Chapa', 'http_status' => $status, 'raw' => $resp];
  }
  $decoded['_http_status'] = $status;
  return ['ok' => true, 'data' => $decoded];
}

// Get listing fee percentage and currency by property type
function get_listing_fee_by_property_type(string $propertyType): array {
  global $pdo;
  $stmt = $pdo->prepare('SELECT listing_fee, currency FROM admin_price_settings WHERE property_type = :pt LIMIT 1');
  $stmt->execute([':pt' => $propertyType]);
  $result = $stmt->fetch();
  return $result ?: ['listing_fee' => 5.00, 'currency' => 'ETB'];
}

// Check if auto-approval is enabled for pending properties
function should_auto_approve_pending_properties(PDO $pdo): bool {
  $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'auto_approve_pending_properties' LIMIT 1");
  $stmt->execute();
  $row = $stmt->fetch();
  return (string)($row['setting_value'] ?? '0') === '1';
}

// Generate frontend SPA URL with proper routing
function frontend_spa_url(string $hashPath): string {
  $basePath = project_base_url();
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $hostName = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
  $normalizedHash = ltrim($hashPath, '/');

  // In test mode, prefer Vite dev server to avoid Apache module MIME/path mismatches.
  if (stripos(CHAPA_TEST_SECRET_KEY, 'CHASECK_TEST-') === 0) {
    return 'http://' . $hostName . ':5173/#/' . $normalizedHash;
  }

  return $scheme . '://' . $host . $basePath . '/frontend/dist/index.html#/' . $normalizedHash;
}

// Main router for Telebirr payment processing actions
switch ($action) {
  case 'initialize':
    // Initialize Telebirr payment for property listing fee
    require_method('POST');
    $user = require_auth(['owner', 'admin']);
    $input = get_input();
    $propertyId = safe_int($input['property_id'] ?? 0, 0);
    if ($propertyId <= 0) send_error('property_id is required');

    // Verify property ownership and get property details
    $pStmt = $pdo->prepare('SELECT owner_id, status, property_type, price FROM properties WHERE id=:id LIMIT 1');
    $pStmt->execute([':id' => $propertyId]);
    $p = $pStmt->fetch();
    if (!$p) send_error('Property not found', 404);
    if ($user['role'] === 'owner' && (int)$p['owner_id'] !== (int)$user['id']) send_error('Forbidden', 403);

    // Calculate listing fee based on property type percentage
    $priceSettings = get_listing_fee_by_property_type($p['property_type']);
    $feePercent = (float)($priceSettings['listing_fee'] ?? 0);
    if ($feePercent <= 0 || $feePercent > 100) {
      send_error('Invalid admin fee percentage configured for this property type', 400);
    }
    $propertyPrice = (float)($p['price'] ?? 0);
    if ($propertyPrice <= 0) {
      send_error('Property price must be greater than 0 before payment initialization', 400);
    }
    $calculatedAmount = round(($propertyPrice * $feePercent) / 100, 2);
    if ($calculatedAmount <= 0) {
      send_error('Calculated fee amount must be greater than 0', 400);
    }
    $amount = number_format($calculatedAmount, 2, '.', '');
    $currency = (string)$priceSettings['currency'];

    // Generate unique transaction reference
    $txRef = 'propfee_' . $propertyId . '_' . time() . '_' . bin2hex(random_bytes(4));

    // Create pending payment record in database
    $payStmt = $pdo->prepare(
      'INSERT INTO payments (property_id, user_id, tx_ref, amount, currency, status)
       VALUES (:pid, :uid, :tx, :amt, :cur, \'pending\')'
    );
    $payStmt->execute([
      ':pid' => $propertyId,
      ':uid' => (int)$user['id'],
      ':tx' => $txRef,
      ':amt' => $amount,
      ':cur' => $currency,
    ]);

    // Generate Telebirr payment page URL for frontend redirect
    $basePath = project_base_url();
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $paymentPageUrl = $scheme . '://' . $host . $basePath . '/backend/chapa_payment_telebirr.php?action=payment_page&tx_ref=' . $txRef;

    // Return initialization response with payment details
    json_response([
      'ok' => true,
      'tx_ref' => $txRef,
      'payment_page_url' => $paymentPageUrl,
      'amount' => $amount,
      'currency' => $currency,
    ]);
    break;

  case 'payment_page':
    // Display Telebirr payment page to user
    $txRef = (string)($_GET['tx_ref'] ?? '');
    if ($txRef === '') {
      echo '<h1>Payment Error</h1><p>Missing transaction reference</p>';
      exit;
    }

    // Verify payment record exists and get property details
    $payStmt = $pdo->prepare('SELECT p.*, pr.city, pr.subcity, pr.real_address, pr.property_type FROM payments p JOIN properties pr ON p.property_id = pr.id WHERE p.tx_ref=:tx LIMIT 1');
    $payStmt->execute([':tx' => $txRef]);
    $payment = $payStmt->fetch();
    if (!$payment) {
      echo '<h1>Payment Error</h1><p>Invalid transaction reference</p>';
      exit;
    }

    // Get user details for form pre-population
    $userStmt = $pdo->prepare('SELECT full_name, phone FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $payment['user_id']]);
    $user = $userStmt->fetch();

    // Get dynamic price settings for fee display
    $priceSettings = get_listing_fee_by_property_type($payment['property_type']);
    $feeLabel = number_format((float)($priceSettings['listing_fee'] ?? 0), 2) . '% of listing price';
    $dashboardUrl = frontend_spa_url('dashboard');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pay listing fee · Telebirr · EthioRent</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <meta name="theme-color" content="#0a0a0b">
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased selection:bg-emerald-500/30 selection:text-white">
        <!-- Dark ambient background with TeleBirr branding -->
        <div class="pointer-events-none fixed inset-0 overflow-hidden">
            <!-- TeleBirr full-page background image -->
            <div class="absolute inset-0 opacity-12 mix-blend-overlay">
                <img src="../frontend/TeleBirr.png" alt="TeleBirr Background" 
                     class="w-full h-full object-cover filter brightness-75 contrast-125 saturate-150" />
            </div>
            
            <!-- Overlay gradients for depth and readability -->
            <div class="absolute inset-0 bg-gradient-to-br from-zinc-900/40 via-zinc-800/30 to-zinc-900/50"></div>
            <div class="absolute inset-0 bg-[radial-gradient(ellipse_80%_50%_at_50%_-20%,rgba(16,185,129,0.15),transparent)]"></div>
            <div class="absolute -right-32 -top-32 h-[28rem] w-[28rem] rounded-full bg-emerald-500/8 blur-3xl"></div>
            <div class="absolute -bottom-20 -left-32 h-96 w-96 rounded-full bg-cyan-500/6 blur-3xl"></div>
            <div class="absolute right-1/4 top-1/3 h-64 w-64 rounded-full bg-violet-500/4 blur-3xl"></div>
        </div>

        <div class="relative mx-auto min-h-screen max-w-5xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
            <!-- Payment page header with navigation -->
            <header class="mb-8 flex flex-col gap-4 sm:mb-10 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-center gap-3">
                    <button type="button" onclick="goBack()" class="group flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl border border-zinc-700/80 bg-zinc-900/80 text-zinc-300 shadow-lg shadow-black/20 backdrop-blur-sm transition hover:border-emerald-500/40 hover:bg-zinc-800 hover:text-emerald-400" aria-label="Go back">
                        <i class="fas fa-arrow-left text-sm transition group-hover:-translate-x-0.5"></i>
                    </button>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-emerald-400/90">Step 2 of 2</p>
                        <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Complete listing payment</h1>
                        <p class="mt-0.5 text-sm text-zinc-200">Pay your one-time fee with Telebirr to submit your property for review.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 rounded-2xl border border-zinc-700/60 bg-zinc-900/70 px-4 py-2.5 text-sm text-zinc-300 shadow-lg shadow-black/20 backdrop-blur-md">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/20 text-amber-300">
                        <i class="fas fa-bolt text-xs"></i>
                    </span>
                    <span class="font-medium text-white">Telebirr</span>
                    <span class="text-zinc-400">|</span>
                    <span class="text-zinc-300">Chapa</span>
                </div>
            </header>

            <div class="grid gap-8 lg:grid-cols-12 lg:gap-10">
                <!-- Payment summary sidebar -->
                <aside class="lg:col-span-5">
                    <div class="sticky top-8 space-y-4">
                        <div class="overflow-hidden rounded-2xl border border-zinc-700/50 bg-zinc-900/60 shadow-2xl shadow-black/40 ring-1 ring-white/5 backdrop-blur-sm">
                            <div class="relative border-b border-emerald-500/20 bg-gradient-to-br from-emerald-600 via-emerald-700 to-teal-900 px-5 py-6 text-white">
                                <div class="pointer-events-none absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-40"></div>
                                <p class="relative text-sm font-medium text-emerald-100/90">Amount due</p>
                                <p class="relative mt-1 flex items-baseline gap-2">
                                    <span class="text-4xl font-bold tracking-tight text-white"><?php echo htmlspecialchars($payment['amount']); ?></span>
                                    <span class="text-lg font-semibold text-emerald-200/90"><?php echo htmlspecialchars($payment['currency']); ?></span>
                                </p>
                                <p class="relative mt-3 text-sm text-emerald-100/80">Listing fee (<?php echo htmlspecialchars($feeLabel); ?>)</p>
                            </div>
                            <div class="space-y-4 p-5">
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Property</p>
                                    <p class="mt-1 font-semibold text-white"><?php echo htmlspecialchars($payment['property_type']); ?></p>
                                    <p class="mt-0.5 text-sm text-zinc-200"><?php echo htmlspecialchars($payment['city'] . ' · ' . $payment['subcity']); ?></p>
                                </div>
                                <div class="rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2.5 text-sm text-amber-100/95">
                                    <i class="fas fa-hourglass-half mr-2 text-amber-400/90"></i>
                                    Your listing will move to <strong class="text-amber-50">pending review</strong> after successful payment.
                                </div>
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Reference</p>
                                    <div class="mt-1 flex items-center gap-2">
                                        <code class="block flex-1 truncate rounded-lg border border-zinc-700/80 bg-zinc-950/80 px-2 py-1.5 text-xs text-emerald-300/90" id="refText"><?php echo htmlspecialchars($txRef); ?></code>
                                        <button type="button" onclick="copyRef(this)" class="shrink-0 rounded-lg border border-zinc-600 bg-zinc-800 px-2.5 py-1.5 text-xs font-medium text-white transition hover:border-zinc-500 hover:bg-zinc-700" title="Copy">Copy</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>

                <!-- Payment form main column -->
                <div class="lg:col-span-7">
                    <div class="rounded-2xl border border-zinc-700/50 bg-zinc-900/50 p-6 shadow-2xl shadow-black/30 ring-1 ring-white/5 backdrop-blur-sm sm:p-8">
                        <div class="mb-8 flex items-start gap-4">
                            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
                                <i class="fas fa-mobile-screen-button text-lg"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-bold text-white">Payer details</h2>
                                <p class="mt-1 text-sm leading-relaxed text-zinc-200">We use this for Chapa to process your <span class="font-medium text-emerald-400/90">Telebirr</span> payment. Your Telebirr wallet phone should match the number below when possible.</p>
                            </div>
                        </div>

                        <form id="paymentForm" class="space-y-6">
                            <div>
                                <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">Name</p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="firstName" class="mb-1.5 block text-sm font-medium text-zinc-200">First name <span class="text-rose-400">*</span></label>
                                        <input type="text" id="firstName" name="firstName" required autocomplete="given-name"
                                               value="<?php echo htmlspecialchars(explode(' ', $user['full_name'])[0] ?? ''); ?>"
                                               class="w-full rounded-xl border border-zinc-600 bg-zinc-950/50 px-3.5 py-2.5 text-zinc-100 shadow-inner transition placeholder:text-zinc-600 focus:border-emerald-500/80 focus:outline-none focus:ring-2 focus:ring-emerald-500/25">
                                    </div>
                                    <div>
                                        <label for="lastName" class="mb-1.5 block text-sm font-medium text-zinc-200">Last name <span class="text-rose-400">*</span></label>
                                        <input type="text" id="lastName" name="lastName" required autocomplete="family-name"
                                               value="<?php echo htmlspecialchars(explode(' ', $user['full_name'])[1] ?? ''); ?>"
                                               class="w-full rounded-xl border border-zinc-600 bg-zinc-950/50 px-3.5 py-2.5 text-zinc-100 shadow-inner transition placeholder:text-zinc-600 focus:border-emerald-500/80 focus:outline-none focus:ring-2 focus:ring-emerald-500/25">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label for="email" class="mb-1.5 block text-sm font-medium text-zinc-200">Email <span class="text-rose-400">*</span></label>
                                <input type="email" id="email" name="email" required autocomplete="email"
                                       placeholder="you@example.com"
                                       class="w-full rounded-xl border border-zinc-600 bg-zinc-950/50 px-3.5 py-2.5 text-zinc-100 transition focus:border-emerald-500/80 focus:outline-none focus:ring-2 focus:ring-emerald-500/25">
                            </div>

                            <div>
                                <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">Telebirr wallet</p>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="phoneNumber" class="mb-1.5 block text-sm font-medium text-zinc-200">Phone (09… ) <span class="text-rose-400">*</span></label>
                                        <div class="relative">
                                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-zinc-500"><i class="fas fa-phone text-sm"></i></span>
                                            <input type="tel" id="phoneNumber" name="phoneNumber" required
                                                   value="<?php echo htmlspecialchars($user['phone']); ?>"
                                                   pattern="09[0-9]{8}"
                                                   placeholder="09XXXXXXXX"
                                                   class="w-full rounded-xl border border-zinc-600 bg-zinc-950/50 py-2.5 pl-10 pr-3 text-zinc-100 transition focus:border-emerald-500/80 focus:outline-none focus:ring-2 focus:ring-emerald-500/25">
                                        </div>
                                    </div>
                                    <div>
                                        <label for="pin" class="mb-1.5 block text-sm font-medium text-zinc-200">6-digit Telebirr PIN <span class="text-rose-400">*</span></label>
                                        <input type="password" id="pin" name="pin" required inputmode="numeric"
                                               maxlength="6" pattern="[0-9]{6}"
                                               placeholder="••••••"
                                               autocomplete="one-time-code"
                                               class="w-full rounded-xl border border-zinc-600 bg-zinc-950/50 px-3.5 py-2.5 tracking-widest text-zinc-100 transition focus:border-emerald-500/80 focus:outline-none focus:ring-2 focus:ring-emerald-500/25">
                                        <p class="mt-1.5 text-xs text-zinc-400">Never share your PIN. EthioRent staff will never ask for it.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="amount" class="mb-1.5 block text-sm font-medium text-zinc-300">Amount</label>
                                    <input type="text" id="amount" name="amount" required
                                           value="<?php echo htmlspecialchars($payment['amount']); ?>"
                                           readonly
                                           class="w-full cursor-not-allowed rounded-xl border border-zinc-700 bg-zinc-800/80 px-3.5 py-2.5 text-zinc-300">
                                </div>
                                <div>
                                    <label for="currency" class="mb-1.5 block text-sm font-medium text-zinc-300">Currency</label>
                                    <input type="text" id="currency" name="currency" required
                                           value="<?php echo htmlspecialchars($payment['currency']); ?>"
                                           readonly
                                           class="w-full cursor-not-allowed rounded-xl border border-zinc-700 bg-zinc-800/80 px-3.5 py-2.5 text-zinc-300">
                                </div>
                            </div>


                            <button type="submit" id="payButton"
                                    class="group relative w-full overflow-hidden rounded-2xl bg-gradient-to-r from-emerald-500 via-emerald-600 to-teal-600 py-3.5 text-base font-semibold text-white shadow-lg shadow-emerald-900/40 transition hover:from-emerald-400 hover:via-emerald-500 hover:to-teal-500 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 focus:ring-offset-zinc-950 disabled:cursor-not-allowed disabled:opacity-60">
                                <span class="relative z-10 flex items-center justify-center gap-2">
                                    <i class="fas fa-lock text-sm opacity-90"></i>
                                    Pay <?php echo htmlspecialchars($payment['amount']); ?> <?php echo htmlspecialchars($payment['currency']); ?> with Telebirr
                                </span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment processing loading overlay -->
        <div id="loadingOverlay" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/85 p-4 backdrop-blur-md hidden">
            <div class="w-full max-w-sm rounded-2xl border border-zinc-700/80 bg-zinc-900 p-8 shadow-2xl shadow-black/50">
                <div class="text-center">
                    <div class="mx-auto mb-4 h-12 w-12 animate-spin rounded-full border-2 border-zinc-600 border-t-emerald-400"></div>
                    <p class="text-lg font-semibold text-zinc-100">Processing your payment</p>
                    <p class="mt-2 text-sm text-zinc-400">Please keep this page open. This usually takes a few seconds.</p>
                </div>
            </div>
        </div>

        <!-- Payment success modal -->
        <div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 p-4 backdrop-blur-lg hidden">
            <div class="w-full max-w-lg overflow-hidden rounded-3xl border border-emerald-500/20 bg-gradient-to-br from-zinc-900 via-zinc-800/95 to-zinc-900/90 shadow-2xl shadow-emerald-900/30 ring-1 ring-emerald-500/10 backdrop-blur-xl">
                <!-- Success animation header -->
                <div class="relative h-32 bg-gradient-to-br from-emerald-500 via-emerald-600 to-teal-600">
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\"60\" height=\"60\" viewBox=\"0 0 60 60\" xmlns=\"http://www.w3.org/2000/svg\"%3E%3Cg fill=\"none\" fill-rule=\"evenodd\"%3E%3Cg fill=\"%23ffffff\" fill-opacity=\"0.1\"%3E%3Cpath d=\"M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\"/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity=30"></div>
                    
                    <!-- Success icon with animation -->
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="relative">
                            <div class="flex h-20 w-20 items-center justify-center rounded-full bg-white/20 backdrop-blur-sm">
                                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-white/30 backdrop-blur-sm">
                                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-white">
                                        <i class="fas fa-check text-2xl text-emerald-600"></i>
                                    </div>
                                </div>
                            </div>
                            <!-- Animated rings -->
                            <div class="absolute inset-0 flex h-20 w-20 items-center justify-center rounded-full border-2 border-emerald-400/30 animate-ping"></div>
                            <div class="absolute inset-0 flex h-20 w-20 items-center justify-center rounded-full border-2 border-emerald-300/20 animate-ping animation-delay-200"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Success modal content -->
                <div class="px-8 py-6 text-center">
                    <div class="mb-6">
                        <h3 class="text-2xl font-bold text-white mb-2">Payment Successful!</h3>
                        <p class="text-zinc-200 leading-relaxed">Your listing fee has been successfully processed. Your property is now in the queue for admin review.</p>
                    </div>
                    
                    <!-- Success status details -->
                    <div class="mb-6 space-y-3">
                        <div class="flex items-center justify-center gap-3 rounded-2xl border border-emerald-500/20 bg-emerald-500/10 px-4 py-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500/20">
                                <i class="fas fa-home text-sm text-emerald-300"></i>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-emerald-100">Property Status</p>
                                <p class="text-xs text-emerald-200/80">Pending admin review</p>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-center gap-3 rounded-2xl border border-blue-500/20 bg-blue-500/10 px-4 py-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-500/20">
                                <i class="fas fa-clock text-sm text-blue-300"></i>
                            </div>
                            <div class="text-left">
                                <p class="text-sm font-medium text-blue-100">Review Time</p>
                                <p class="text-xs text-blue-200/80">Usually within 24 hours</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Auto-redirect countdown -->
                    <div class="mb-4">
                        <div class="flex items-center justify-center gap-2 text-zinc-300">
                            <i class="fas fa-spinner fa-spin text-emerald-400"></i>
                            <span class="text-sm">Redirecting to your dashboard in <span id="redirectCountdown" class="font-bold text-emerald-400">5</span> seconds...</span>
                        </div>
                    </div>
                    
         
                </div>
            </div>
        </div>
        
        <style>
            @keyframes ping {
                75%, 100% {
                    transform: scale(2);
                    opacity: 0;
                }
            }
            .animate-ping {
                animation: ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
            }
            .animation-delay-200 {
                animation-delay: 200ms;
            }
        </style>

        <!-- Payment failure modal -->
        <div id="failureModal" class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/85 p-4 backdrop-blur-md hidden">
            <div class="w-full max-w-md rounded-2xl border border-zinc-600/80 bg-zinc-900 p-8 text-center shadow-2xl shadow-black/50 ring-1 ring-white/5">
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl border border-rose-500/30 bg-rose-500/10 text-rose-400">
                    <i class="fas fa-triangle-exclamation text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-zinc-50">Payment could not be completed</h3>
                <p class="mt-2 text-zinc-400" id="errorMessage">We couldn&rsquo;t process your payment. Please try again.</p>
                <button type="button" onclick="location.reload()"
                        class="mt-6 w-full rounded-xl border border-rose-500/40 bg-rose-600 py-3 text-sm font-semibold text-white transition hover:bg-rose-500">
                    Try again
                </button>
            </div>
        </div>

        <script>
            // Navigation helper function
            function goBack() {
                window.history.back();
                return false;
            }
            // Copy transaction reference to clipboard
            function copyRef(btn) {
                const el = document.getElementById('refText');
                if (!el) return;
                const t = el.textContent || '';
                const showCopied = function() {
                    if (btn) {
                        const o = btn.textContent;
                        btn.textContent = 'Copied';
                        setTimeout(function() { btn.textContent = o; }, 1500);
                    }
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(showCopied).catch(function() { window.prompt('Copy reference:', t); });
                } else {
                    window.prompt('Copy reference:', t);
                }
            }

            // Store original button HTML for restoration
            const payDefaultHtml = document.getElementById('payButton').innerHTML;

            // Payment form submission handler
            document.getElementById('paymentForm').addEventListener('submit', async function(e) {
                e.preventDefault();

                const formData = new FormData(this);
                const payButton = document.getElementById('payButton');
                const loadingOverlay = document.getElementById('loadingOverlay');

                // Disable button and show loading state
                payButton.disabled = true;
                payButton.innerHTML = '<span class="inline-flex items-center gap-2"><i class="fas fa-spinner fa-spin"></i>Processing…</span>';
                loadingOverlay.classList.remove('hidden');

                try {
                    // Send payment data to backend
                    const response = await fetch('?action=process_payment&tx_ref=<?php echo urlencode($txRef); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            firstName: formData.get('firstName'),
                            lastName: formData.get('lastName'),
                            email: formData.get('email'),
                            phoneNumber: formData.get('phoneNumber'),
                            pin: formData.get('pin'),
                            amount: formData.get('amount'),
                            currency: formData.get('currency')
                        })
                    });

                    const data = await response.json();
                    loadingOverlay.classList.add('hidden');

                    // Handle payment response
                    if (data.ok && data.success) {
                        document.getElementById('successModal').classList.remove('hidden');
                        startRedirectCountdown();
                    } else {
                        document.getElementById('errorMessage').textContent = data.error || 'Payment failed. Please try again.';
                        document.getElementById('failureModal').classList.remove('hidden');
                    }
                } catch (error) {
                    loadingOverlay.classList.add('hidden');
                    document.getElementById('errorMessage').textContent = 'Network error. Please try again.';
                    document.getElementById('failureModal').classList.remove('hidden');
                } finally {
                    // Restore button state
                    payButton.disabled = false;
                    payButton.innerHTML = payDefaultHtml;
                }
            });

            // Auto-redirect countdown timer
            function startRedirectCountdown() {
                const secondsEl = document.getElementById('redirectCountdown');
                let seconds = 5;
                const redirectUrl = <?php echo json_encode($dashboardUrl); ?>;
                const timer = setInterval(function() {
                    seconds -= 1;
                    if (secondsEl) {
                        secondsEl.textContent = String(Math.max(seconds, 0));
                    }
                    if (seconds <= 0) {
                        clearInterval(timer);
                        window.location.href = redirectUrl;
                    }
                }, 1000);
            }
        </script>
    </body>
    </html>
    <?php
    exit;

  case 'process_payment':
    // Process Telebirr payment submission
    require_method('POST');
    $txRef = (string)($_GET['tx_ref'] ?? '');
    $input = get_input();
    
    if ($txRef === '') {
      json_response(['ok' => false, 'error' => 'Missing transaction reference']);
    }

    // Validate required form fields
    $requiredFields = ['firstName', 'lastName', 'email', 'phoneNumber', 'pin', 'amount', 'currency'];
    foreach ($requiredFields as $field) {
      if (empty($input[$field])) {
        json_response(['ok' => false, 'error' => "Missing required field: $field"]);
      }
    }

    // Get payment and property details
    $payStmt = $pdo->prepare('SELECT p.*, pr.property_type FROM payments p JOIN properties pr ON p.property_id = pr.id WHERE p.tx_ref=:tx LIMIT 1');
    $payStmt->execute([':tx' => $txRef]);
    $payment = $payStmt->fetch();
    
    if (!$payment) {
      json_response(['ok' => false, 'error' => 'Payment not found']);
    }

    // Get user information for Chapa processing
    $userStmt = $pdo->prepare('SELECT full_name, phone FROM users WHERE id = :id LIMIT 1');
    $userStmt->execute([':id' => $payment['user_id']]);
    $user = $userStmt->fetch();

    // Check if in Chapa test mode to bypass network calls
    $isChapaTestMode = stripos(CHAPA_TEST_SECRET_KEY, 'CHASECK_TEST-') === 0;
    $success = false;

    if ($isChapaTestMode) {
      // Mock success for testing
      $success = true;
      error_log('Telebirr test-mode mock success for tx_ref: ' . $txRef);
    } else {
      // Prepare Chapa API payload for Telebirr payment
      $payload = [
        'amount' => $payment['amount'],
        'currency' => $payment['currency'],
        'email' => $input['email'],
        'first_name' => $input['firstName'],
        'last_name' => $input['lastName'],
        'phone_number' => $input['phoneNumber'],
        'tx_ref' => $txRef,
        'callback_url' => project_base_url() . '/backend/chapa_payment_telebirr.php?action=callback',
        'return_url' => frontend_spa_url('admin'),
        'customization' => [
          'title' => 'Property Listing Fee - Telebirr',
          'description' => 'Payment for property listing on House Rental Platform',
        ],
        'payment_method' => 'telebirr',
      ];

      // Set up Chapa API headers
      $headers = [
        'Authorization: Bearer ' . CHAPA_TEST_SECRET_KEY,
        'Content-Type: application/json',
      ];

      // Initialize Chapa payment transaction
      $resp = chapa_request('POST', 'https://api.chapa.co/v1/transaction/initialize', $payload, $headers);
      if (!$resp['ok']) {
        json_response(['ok' => false, 'error' => 'Chapa initialization failed: ' . ($resp['error'] ?? 'Unknown error')]);
      }

      $data = $resp['data'];
      error_log('Chapa Response Structure: ' . json_encode($data));
      $success = true;
    }
    
    if ($success) {
      // Update payment status to successful
      $upd = $pdo->prepare('UPDATE payments SET status=\'success\', updated_at=NOW() WHERE id=:id');
      $upd->execute([':id' => (int)$payment['id']]);
      
      // Update property status based on auto-approval setting
      $nextStatus = should_auto_approve_pending_properties($pdo) ? 'active' : 'pending';
      $pUpd = $pdo->prepare('UPDATE properties SET status=:st, updated_at=NOW() WHERE id=:pid');
      $pUpd->execute([':pid' => (int)$payment['property_id'], ':st' => $nextStatus]);
      
      json_response(['ok' => true, 'success' => true, 'message' => 'Payment processed successfully']);
    } else {
      json_response(['ok' => false, 'success' => false, 'error' => 'Payment verification failed']);
    }
    break;

  case 'callback':
    // Handle Chapa webhook callback for payment verification
    $trxRef = (string)($_GET['trx_ref'] ?? $_GET['tx_ref'] ?? $_POST['tx_ref'] ?? '');
    if ($trxRef === '') send_error('Missing tx_ref', 400);

    // Verify transaction status with Chapa API
    $url = 'https://api.chapa.co/v1/transaction/verify/' . rawurlencode($trxRef);
    $headers = ['Authorization: Bearer ' . CHAPA_TEST_SECRET_KEY];

    $resp = chapa_request('GET', $url, [], $headers);
    if (!$resp['ok']) {
      send_error('Chapa verify failed', 502, ['details' => $resp]);
    }

    $verifyData = $resp['data'];
    $status = (string)($verifyData['data']['status'] ?? $verifyData['status'] ?? '');

    // Find payment record in database
    $payStmt = $pdo->prepare('SELECT id, property_id FROM payments WHERE tx_ref=:tx LIMIT 1');
    $payStmt->execute([':tx' => $trxRef]);
    $payment = $payStmt->fetch();
    if (!$payment) send_error('Payment reference not found', 404);

    // Update payment status based on Chapa response
    $newStatus = ($status === 'success') ? 'success' : 'failed';
    $upd = $pdo->prepare('UPDATE payments SET status=:st, updated_at=NOW() WHERE id=:id');
    $upd->execute([':st' => $newStatus, ':id' => (int)$payment['id']]);

    // Update property status based on payment outcome
    if ($status === 'success') {
      $nextStatus = should_auto_approve_pending_properties($pdo) ? 'active' : 'pending';
      $pUpd = $pdo->prepare('UPDATE properties SET status=:st, updated_at=NOW() WHERE id=:pid');
      $pUpd->execute([':pid' => (int)$payment['property_id'], ':st' => $nextStatus]);
    } else {
      $pUpd = $pdo->prepare('UPDATE properties SET status=\'rejected\', updated_at=NOW() WHERE id=:pid');
      $pUpd->execute([':pid' => (int)$payment['property_id']]);
    }

    // Redirect user to admin dashboard after callback processing
    $redirect = frontend_spa_url('admin');
    header('Location: ' . $redirect);
    exit;

  default:
    // Handle unknown payment actions
    send_error('Unknown action', 400);
}
?>
