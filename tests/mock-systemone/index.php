<?php

    /**
     * A mock System One endpoint for FederationJevGuard's test environment, the tests never contact TypeSafe.
     *
     * POST /v1/systemone answers every question with a fixed answer decided by a marker in the state:
     *  - JEVGUARD_TEST_SPAM:   the content is clearly spam, the entity should be blacklisted
     *  - JEVGUARD_TEST_NORMAL: the content is not spam, the entity should not be blacklisted
     *  - anything else:        the model is unsure, every option is equally likely
     * A state holding JEVGUARD_TEST_TOO_LONG that is longer than 2000 characters is rejected like a state that exceeds
     * the model's context (HTTP 400, max_tokens_exceeded).
     *
     * GET /requests returns what every request asked (the model, the question names, whether images were sent and the
     * size of the state), DELETE /requests forgets them.
     */

    const LOG = '/tmp/mock-systemone-requests.jsonl';

    function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        print(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if($path === '/requests')
    {
        if($method === 'DELETE')
        {
            @unlink(LOG);
            respond(200, ['success' => true]);
        }

        $requests = is_file(LOG) ? array_map(fn($line) => json_decode($line, true), file(LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
        respond(200, $requests);
    }

    if($path !== '/v1/systemone' || $method !== 'POST')
    {
        respond(404, ['detail' => 'Not Found']);
    }

    // A JSON body, or with images a multipart body whose first part is the JSON request
    $images = 0;
    if(isset($_FILES['request']['tmp_name']))
    {
        $raw = file_get_contents($_FILES['request']['tmp_name']);
        $images = count($_FILES) - 1;
    }
    else
    {
        $raw = $_POST['request'] ?? file_get_contents('php://input');
        $images = count($_FILES);
    }

    $request = json_decode((string)$raw, true);
    if(!is_array($request) || !isset($request['model'], $request['state'], $request['questions']) || !is_array($request['questions']))
    {
        respond(422, ['detail' => [['loc' => ['body'], 'msg' => 'Field required', 'type' => 'missing']]]);
    }

    $imagesHeader = strtolower($_SERVER['HTTP_TYPESAFE_DANGER_ZONE_IMAGES'] ?? '') === 'true';
    if(isset($request['assets']) && !$imagesHeader)
    {
        respond(400, ['detail' => 'Images are not turned on']);
    }

    $state = json_encode($request['state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents(LOG, json_encode([
        'model' => $request['model'],
        'questions' => array_keys($request['questions']),
        'images' => $images,
        'images_header' => $imagesHeader,
        'state_length' => strlen($state),
        'authorization' => isset($_SERVER['HTTP_AUTHORIZATION']),
    ]) . "\n", FILE_APPEND | LOCK_EX);

    if(str_contains($state, 'JEVGUARD_TEST_TOO_LONG') && strlen($state) > 2000)
    {
        respond(400, ['detail' => 'max_tokens_exceeded: the request is longer than the context length of the model']);
    }

    $kind = str_contains($state, 'JEVGUARD_TEST_SPAM') ? 'spam' : (str_contains($state, 'JEVGUARD_TEST_NORMAL') ? 'normal' : 'unsure');
    $answers = [];
    foreach($request['questions'] as $name => $question)
    {
        $type = $question['type'] ?? 'noul';
        if($type === 'choice')
        {
            $options = array_map('strval', array_keys($question['criteria'] ?? []));
            $preferred = match(true)
            {
                in_array('MALICIOUS', $options, true) => ['spam' => 'MALICIOUS', 'normal' => 'NORMAL'][$kind] ?? null,
                in_array('BLACKLIST', $options, true) => ['spam' => 'BLACKLIST', 'normal' => 'DO_NOT_BLACKLIST'][$kind] ?? null,
                in_array('SPAM', $options, true) => $kind === 'unsure' ? null : 'SPAM',
                default => $kind === 'unsure' ? null : ($options[0] ?? null),
            };

            $probabilities = [];
            foreach($options as $option)
            {
                $probabilities[$option] = $preferred === null ? round(1 / max(1, count($options)), 4) : ($option === $preferred ? 0.94 : round(0.06 / max(1, count($options) - 1), 4));
            }

            $choice = $preferred ?? ($options[0] ?? '');
            $confidence = count($options) > 1 ? round((max($probabilities) - 1 / count($options)) / (1 - 1 / count($options)), 4) : 1.0;
            $answers[$name] = ['type' => 'choice', 'choice' => $choice, 'confidence' => $confidence, 'probabilities' => $probabilities];
        }
        elseif($type === 'score')
        {
            $levels = count($question['criteria'] ?? []);
            $level = ['spam' => $levels - 2, 'normal' => 0][$kind] ?? null;
            $probabilities = [];
            for($i = 0; $i < $levels; $i++)
            {
                $probabilities[(string)$i] = $level === null ? round(1 / $levels, 4) : ($i === $level ? 1.0 : 0.0);
            }

            $answers[$name] = ['type' => 'score', 'score' => $level ?? ($levels - 1) / 2, 'confidence' => $level === null ? 0.0 : 1.0,
                'legend' => array_combine(array_map('strval', array_keys($question['criteria'])), $question['criteria']), 'probabilities' => $probabilities];
        }
        else
        {
            // The checks against a decision (mistaken classifications, a compromised account, steering the reviewer)
            // are always absent, the signals follow the kind of content
            $negative = preg_match('/addresses_reviewer|mistaken|compromised/', (string)$name) === 1;
            $answers[$name] = ['type' => 'noul', 'noul' => $negative ? 0.02 : (['spam' => 0.95, 'normal' => 0.03][$kind] ?? 0.5)];
        }
    }

    respond(200, ['model' => 'mock-jev-1.0.0', 'answers' => $answers, 'usage' => ['input_tokens' => intdiv(strlen($state), 4) + 100, 'output_tokens' => count($answers) * 4]]);
