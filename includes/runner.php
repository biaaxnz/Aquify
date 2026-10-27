<?php
/** Jalankan analisis untuk satu titik dan simpan hasilnya. $mode: 'rule' | 'ai'. */
function analyze_point(int $pid, string $mode = 'rule'): array {
    $rows = q_all('SELECT spacing_m, rho_a FROM measurements WHERE point_id=? ORDER BY spacing_m', [$pid]);
    $S = build_sounding($rows, (float)cfg('analysis.depth_factor', 0.5));
    $w = q_one('SELECT * FROM water_samples WHERE point_id=? ORDER BY measured_at DESC, id DESC LIMIT 1', [$pid]);
    $wa = $w ? water_assess($w) : null;
    $res = analyze_sounding($S, $wa);
    $res['water'] = $wa;
    $res['sounding'] = $S;

    $ai = null;
    $aiLog = null;
    $aiStarted = microtime(true);
    $aiInput = null;
    $mode = $mode === 'ai' ? 'ai' : 'rule';
    $aiMeta = null;
    if ($mode === 'ai') {
        $aiInput = aq_ai_context($pid, $res, $S);
        $g = gemini_deep($aiInput);
        if ($g['ok']) {
            $clean = aq_ai_sanitize($g['data'], $res);
            $ai = json_encode(['v' => 2, 'model' => $g['model'], 'ms' => $g['ms'], 'tokens' => $g['tokens'] ?? null, 'data' => $clean], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $res['ai_error'] = null;
        } else {
            $res['ai_error'] = $g['error'];
        }
        $aiMeta = $g;
    }
    q('INSERT INTO analyses(point_id,mode,score,verdict,best_depth,best_rho,result_json,ai_text) VALUES(?,?,?,?,?,?,?,?)',
      [$pid, $mode, $res['score'], $res['verdict'], $res['best']['depth'] ?? null, $res['best']['rho'] ?? null,
       json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $ai]);
    $analysisId = (int)pdo()->lastInsertId();
    if ($mode === 'ai') {
        $status = $ai !== null ? 'success' : 'failed';
        q('INSERT INTO ai_analysis_logs(analysis_id,point_id,provider,model,prompt_text,response_text,input_json,output_json,status,error_message,duration_ms)
           VALUES(?,?,?,?,?,?,?,?,?,?,?)',
          [$analysisId, $pid, 'gemini', gemini_model(),
           'Analisis mendalam terstruktur (VES + air + titik sekitar).', $aiMeta['text'] ?? null,
           json_encode($aiInput, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
           json_encode(['result' => $res, 'ai' => $ai ? json_decode($ai, true) : null], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
           $status, $res['ai_error'] ?? null, max(0, (int)round((microtime(true) - $aiStarted) * 1000))]);
    }
    q('INSERT INTO activity_history(action,entity_type,entity_id,status,details_json) VALUES(?,?,?,?,?)',
      ['analysis_completed', 'analyses', (string)$analysisId, $mode === 'ai' && $ai === null ? 'warning' : 'success',
       json_encode(['point_id' => $pid, 'mode' => $mode, 'verdict' => $res['verdict']], JSON_UNESCAPED_UNICODE)]);
    return ['id' => $analysisId, 'result' => $res, 'ai' => $ai];
}
