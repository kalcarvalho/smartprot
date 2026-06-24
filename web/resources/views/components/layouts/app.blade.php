<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SmartProt' }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f7f6; color: #16201d; }
        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; display: flex; flex-direction: column; }
        .topbar { min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 0 32px; background: #ffffff; border-bottom: 1px solid #dfe7e3; }
        .brand { display: flex; align-items: center; gap: 12px; font-weight: 800; letter-spacing: 0; white-space: nowrap; }
        .brand-mark { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 8px; background: #17483f; color: #ffffff; font-weight: 900; }
        .nav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .nav a { min-height: 36px; display: inline-flex; align-items: center; border-radius: 8px; padding: 0 12px; color: #51615b; font-size: 14px; font-weight: 700; }
        .nav a.active, .nav a:hover { background: #e7eeeb; color: #17483f; }
        .nav-actions { display: flex; align-items: center; gap: 12px; color: #51615b; font-size: 14px; }
        .button, button { min-height: 40px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; padding: 0 16px; background: #17483f; color: #ffffff; font-weight: 700; cursor: pointer; font: inherit; }
        .button.secondary, button.secondary { background: #e7eeeb; color: #17483f; }
        .button.danger, button.danger { background: #8f2f2f; color: #ffffff; }
        main { width: min(1120px, calc(100% - 32px)); margin: 32px auto; }
        .auth-page { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, 480px); }
        .auth-visual { padding: 56px; background: #17483f; color: #ffffff; display: flex; flex-direction: column; justify-content: space-between; }
        .auth-visual h1 { margin: 0; font-size: clamp(34px, 5vw, 64px); line-height: 1; letter-spacing: 0; max-width: 780px; }
        .auth-visual p { margin: 24px 0 0; max-width: 560px; color: #d8ebe5; font-size: 18px; line-height: 1.6; }
        .auth-panel { display: grid; place-items: center; padding: 32px; background: #f4f7f6; }
        .login-box { width: 100%; max-width: 380px; }
        .login-box h2, .page-title h1 { margin: 0; font-size: 28px; line-height: 1.2; letter-spacing: 0; }
        .page-title { display: flex; justify-content: space-between; align-items: start; gap: 20px; margin-bottom: 24px; }
        .login-box .muted, .page-title p, .muted { color: #64736d; line-height: 1.5; }
        label { display: block; margin: 18px 0 8px; font-size: 14px; font-weight: 700; }
        input[type="text"], input[type="email"], input[type="password"], select, textarea { width: 100%; min-height: 46px; border: 1px solid #cfd9d5; border-radius: 8px; padding: 0 12px; background: #ffffff; font: inherit; }
        textarea { min-height: 90px; padding-top: 10px; resize: vertical; }
        .error { margin-top: 8px; color: #a83232; font-size: 14px; }
        .flash { margin: 0 0 18px; border: 1px solid #b7d7cd; background: #e9f6f1; color: #17483f; border-radius: 8px; padding: 12px 14px; font-weight: 700; }
        .token { display: block; margin-top: 8px; word-break: break-all; border-radius: 8px; background: #16201d; color: #ffffff; padding: 12px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 13px; }
        .remember { display: flex; align-items: center; gap: 8px; margin: 16px 0 22px; color: #51615b; font-size: 14px; }
        .grid { display: grid; gap: 16px; }
        .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 24px; }
        .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .stat, .panel { background: #ffffff; border: 1px solid #dfe7e3; border-radius: 8px; padding: 20px; }
        .stat span { color: #64736d; font-size: 13px; font-weight: 700; text-transform: uppercase; }
        .stat strong { display: block; margin-top: 10px; font-size: 32px; line-height: 1; }
        .two-col { grid-template-columns: minmax(0, 1.1fr) minmax(320px, .9fr); margin-top: 16px; align-items: start; }
        .panel h2 { margin: 0 0 16px; font-size: 18px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px 0; text-align: left; border-bottom: 1px solid #edf2ef; vertical-align: top; }
        th { color: #64736d; font-size: 12px; text-transform: uppercase; }
        .status { display: inline-flex; align-items: center; min-height: 24px; border-radius: 999px; padding: 0 10px; font-size: 12px; font-weight: 800; background: #e7eeeb; color: #17483f; }
        .status.offline { background: #f1e4e4; color: #883636; }
        .rule-list { display: grid; gap: 10px; }
        .rule-item { display: grid; grid-template-columns: 1fr auto; gap: 14px; align-items: center; border: 1px solid #edf2ef; border-radius: 8px; padding: 12px; }
        .rule-item strong { display: block; margin-bottom: 4px; }
        .actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .pagination { margin-top: 16px; color: #64736d; font-size: 14px; }
        @media (max-width: 900px) { .topbar { align-items: flex-start; flex-direction: column; padding: 16px; } .page-title, .nav-actions { flex-direction: column; align-items: flex-start; } .stats, .two-col, .form-grid { grid-template-columns: 1fr; } }
        @media (max-width: 820px) { .auth-page { grid-template-columns: 1fr; } .auth-visual { min-height: 300px; padding: 32px; } main { margin: 20px auto; } }
    </style>
</head>
<body>
    {{ $slot }}
</body>
</html>
