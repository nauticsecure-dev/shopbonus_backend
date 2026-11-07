<?php

// Read-only staging HTTP regression. The runner supplies the reviewed test plan.
$contextPath = getenv('QA_RUN_CONTEXT');
$reportPath = getenv('QA_JUNIT_PATH');
if (! $contextPath || ! $reportPath || ! is_file($contextPath)) {
    throw new RuntimeException('A controlled staging runner context is required.');
}
$context = json_decode((string) file_get_contents($contextPath), true, 32, JSON_THROW_ON_ERROR);
$origin = parse_url($context['base_url'] ?? '');
$required = array_column($context['execution_plan']['portal-smoke']['required_tests'] ?? [], 'key');
$tests = [
    'PortalSmoke::liveness' => ['/api/v1/health', 200, ['status' => 'ok', 'service' => 'shopbonus-api']],
    'PortalSmoke::readiness' => ['/api/v1/health/ready', 200, ['status' => 'ok']],
    'PortalSmoke::anonymousSpecDenied' => ['/api/v1/admin/qa/openapi.json', 401, ['error.code' => 'unauthenticated']],
    'PortalSmoke::anonymousInventoryDenied' => ['/api/v1/admin/qa/endpoints', 401, ['error.code' => 'unauthenticated']],
];
if (($context['schema_version'] ?? null) !== 1 || ($context['environment'] ?? null) !== 'staging'
    || ! preg_match('/\A[0-9a-f]{40,64}\z/D', $context['deployment_sha'] ?? '')
    || ($origin['scheme'] ?? null) !== 'https' || empty($origin['host']) || ! in_array($origin['path'] ?? '', ['', '/'], true)
    || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($origin))
    || $required === [] || array_diff($required, array_keys($tests))) {
    throw new RuntimeException('The portal smoke plan or staging origin is invalid.');
}

$report = new SimpleXMLElement('<testsuite name="PortalSmoke"/>');
$failed = false;
foreach ($required as $key) {
    [$route, $expectedStatus, $fields] = $tests[$key];
    $case = $report->addChild('testcase');
    $case->addAttribute('class', 'PortalSmoke');
    $case->addAttribute('name', substr($key, strlen('PortalSmoke::')));
    $assertions = 0;
    try {
        $request = curl_init(rtrim($context['base_url'], '/').$route);
        $body = '';
        curl_setopt_array($request, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        if (curl_exec($request) === false) {
            throw new RuntimeException('HTTP request failed or exceeded the response limit.');
        }
        $assertions++;
        if (curl_getinfo($request, CURLINFO_RESPONSE_CODE) !== $expectedStatus) {
            throw new RuntimeException('Unexpected HTTP status.');
        }
        $payload = json_decode($body, true, 32);
        foreach ($fields as $field => $expected) {
            $actual = $payload;
            foreach (explode('.', $field) as $segment) {
                $actual = is_array($actual) ? ($actual[$segment] ?? null) : null;
            }
            $assertions++;
            if ($actual !== $expected) {
                throw new RuntimeException('Unexpected JSON field '.$field.'.');
            }
        }
    } catch (Throwable $error) {
        $failed = true;
        $case->addChild('failure')->addAttribute('message', $error instanceof RuntimeException ? $error->getMessage() : 'HTTP assertion could not run.');
    }
    $case->addAttribute('assertions', (string) $assertions);
}
if (! $report->asXML($reportPath)) {
    throw new RuntimeException('Unable to write the portal smoke report.');
}
exit($failed ? 1 : 0);
