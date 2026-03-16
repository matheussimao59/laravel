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
            gap: 16px;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(219, 228, 240, 0.9);
            background: rgba(255, 255, 255, 0.82);
            backdrop-filter: blur(12px);
        }
        .brand { display: grid; gap: 2px; }
        .brand strong { font-size: 1rem; }
        .brand span { font-size: 0.84rem; color: var(--muted); }
        .menu { display: flex; flex-wrap: wrap; gap: 10px; }
        .menu-link {
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: #334155;
            font-size: 0.92rem;
            font-weight: 600;
        }
        .menu-link.active {
            border-color: var(--primary);
            background: var(--primary-soft);
            color: var(--primary);
        }
        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 28px 20px 48px;
        }
        .container { display: grid; gap: 18px; }
        .page-head { display: grid; gap: 6px; }
        .page-head h1 {
            margin: 0;
            font-size: clamp(1.8rem, 2.6vw, 2.6rem);
        }
        .page-head p { margin: 0; color: var(--muted); max-width: 760px; }
        .status-message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: var(--success);
            font-weight: 600;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }
        .card {
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--panel);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .card-body, .card-content { padding: 18px; }
        .metric-label {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .metric-value {
            margin: 0;
            font-size: clamp(1.8rem, 2vw, 2.4rem);
        }
        .panel-title { margin: 0 0 12px; font-size: 1.15rem; }
        .table-wrap { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; background: #fff; }
        .table th, .table td {
            padding: 12px 10px;
            border-bottom: 1px solid #e5edf6;
            text-align: left;
            vertical-align: middle;
        }
        .table th {
            color: var(--muted);
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .table td { font-size: 0.92rem; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .field { display: grid; gap: 6px; }
        .field label {
            font-size: 0.86rem;
            font-weight: 600;
            color: #334155;
        }
        .form-control {
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #fff;
            font: inherit;
        }
        .actions, .inline-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border: 0;
            border-radius: 12px;
            cursor: pointer;
            font: inherit;
            font-weight: 700;
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
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
        }
        .badge-warning { background: #fff7ed; color: var(--warning); }
        .list { margin: 0; padding-left: 18px; color: #334155; }
        .list li + li { margin-top: 8px; }
        .stack { display: grid; gap: 16px; }
        .muted { color: var(--muted); }
        @media (max-width: 900px) {
            .grid, .form-grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="brand">
                <strong>Painel de Integracoes</strong>
                <span>Mercado Livre atual e novo ambiente beta separados</span>
            </div>
            <nav class="menu" aria-label="Menu principal">
                <a href="{{ route('mercado-livre.dashboard') }}" class="menu-link {{ request()->routeIs('mercado-livre.*') ? 'active' : '' }}">
                    Mercado Livre
                </a>
                <a href="{{ route('mercado-livre-beta.dashboard') }}" class="menu-link {{ request()->routeIs('mercado-livre-beta.*') ? 'active' : '' }}">
                    Mercado livre beta
                </a>
            </nav>
        </header>

        <main class="page">
            @if (session('success'))
                <div class="status-message">{{ session('success') }}</div>
            @endif
            @yield('content')
        </main>
    </div>
</body>
</html>
