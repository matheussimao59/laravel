<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Sistema')</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7fb;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --line: #dbe4f0;
            --primary: #1d4ed8;
            --primary-soft: #dbeafe;
            --success: #166534;
            --warning: #b45309;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
            color: var(--text);
            font-size: 15px;
            line-height: 1.45;
        }
        a { color: inherit; text-decoration: none; }
        .app-shell { min-height: 100vh; }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            border-bottom: 1px solid rgba(219, 228, 240, 0.9);
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(10px);
        }
        .brand { display: grid; gap: 2px; }
        .brand strong { font-size: 0.96rem; }
        .brand span { font-size: 0.78rem; color: var(--muted); }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px 16px 36px;
        }
        .container { display: grid; gap: 14px; }
        .page-head { display: grid; gap: 4px; }
        .page-head h1 {
            margin: 0;
            font-size: clamp(1.45rem, 2vw, 2rem);
            line-height: 1.15;
        }
        .page-head p {
            margin: 0;
            color: var(--muted);
            max-width: 700px;
            font-size: 0.94rem;
        }
        .status-message {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: var(--success);
            font-weight: 600;
            font-size: 0.92rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .card {
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel);
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .card-body, .card-content { padding: 14px; }
        .metric-label {
            margin: 0 0 6px;
            color: var(--muted);
            font-size: 0.84rem;
        }
        .metric-value {
            margin: 0;
            font-size: clamp(1.45rem, 1.7vw, 1.9rem);
            line-height: 1.1;
        }
        .panel-title { margin: 0 0 10px; font-size: 1rem; }
        .panel-subtitle {
            margin: -4px 0 14px;
            color: var(--muted);
            font-size: 0.86rem;
        }
        .table-wrap { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: #fff; }
        .table th, .table td {
            padding: 10px 8px;
            border-bottom: 1px solid #e5edf6;
            text-align: left;
            vertical-align: middle;
        }
        .table th {
            color: var(--muted);
            font-size: 0.72rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .table td { font-size: 0.88rem; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .field { display: grid; gap: 5px; }
        .field label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #334155;
        }
        .form-control {
            width: 100%;
            min-height: 38px;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #fff;
            font: inherit;
        }
        .actions, .inline-form {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 12px;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
            font-size: 0.88rem;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-success { background: #15803d; color: #fff; }
        .btn-light {
            border: 1px solid var(--line);
            background: #fff;
            color: #334155;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .badge-warning { background: #fff7ed; color: var(--warning); }
        .list {
            margin: 0;
            padding-left: 18px;
            color: #334155;
            font-size: 0.9rem;
        }
        .list li + li { margin-top: 6px; }
        .pagination-wrap {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #e5edf6;
        }
        .stack { display: grid; gap: 14px; }
        .muted { color: var(--muted); }
        @media (max-width: 900px) {
            .grid, .form-grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="brand">
                <strong>Painel de Integracoes</strong>
                <span>Gestao de produtos e repricing do Mercado Livre</span>
            </div>
        </header>

        <main class="page">
            @if (session('success'))
                <div class="status-message">{{ session('success') }}</div>
            @endif
            @yield('content')
        </main>
    </div>
    @stack('scripts')
</body>
</html>
