<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriERP | Enterprise Dashboard</title>
    <style>
        :root {
            /* Microsoft Dynamics / SAP Inspired Palette */
            --primary: #005A9E; 
            --primary-hover: #004578;
            --sidebar-bg: #f3f2f1;
            --sidebar-hover: #edebe9;
            --sidebar-text: #323130;
            --body-bg: #faf9f8;
            --card-bg: #ffffff;
            --text-main: #323130;
            --text-muted: #605e5c;
            --border: #e1dfdd;
            --success: #107c10;
            --danger: #d13438;
            --warning: #ffaa44;
            --header-height: 50px;
            --sidebar-width: 250px;
            --radius: 2px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', system-ui, sans-serif; }
        
        body { background-color: var(--body-bg); color: var(--text-main); font-size: 14px; overflow-x: hidden; }

        /* Layout Structure */
        .app-container { display: flex; height: 100vh; overflow: hidden; }
        
        /* Sidebar */
        .sidebar { width: var(--sidebar-width); background: var(--sidebar-bg); border-right: 1px solid var(--border); display: flex; flex-direction: column; transition: width 0.3s ease; }
        .sidebar.collapsed { width: 60px; }
        .sidebar-header { height: var(--header-height); display: flex; align-items: center; padding: 0 16px; font-weight: 600; font-size: 16px; color: var(--primary); border-bottom: 1px solid var(--border); cursor: pointer;}
        .sidebar-nav { flex: 1; overflow-y: auto; padding-top: 10px; }
        .nav-item { display: flex; align-items: center; padding: 12px 16px; color: var(--sidebar-text); text-decoration: none; transition: 0.2s; white-space: nowrap; }
        .nav-item:hover, .nav-item.active { background: var(--sidebar-hover); border-left: 3px solid var(--primary); }
        .nav-icon { width: 20px; text-align: center; margin-right: 12px; font-weight: bold; }
        .sidebar.collapsed .nav-text { display: none; }

        /* Main Content */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        
        /* Topbar */
        .topbar { height: var(--header-height); background: var(--card-bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .topbar-right { display: flex; align-items: center; gap: 15px; }
        .user-profile { display: flex; align-items: center; gap: 8px; font-weight: 500; }
        .btn-logout { background: var(--danger); color: white; border: none; padding: 6px 12px; border-radius: var(--radius); cursor: pointer; text-decoration: none; font-size: 12px; }

        /* Content Area */
        .content-area { flex: 1; padding: 24px; overflow-y: auto; }
        .page-title { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: var(--text-main); }
        
        /* Dashboard Cards */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .kpi-card { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-direction: column; }
        .kpi-title { font-size: 12px; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 8px; }
        .kpi-value { font-size: 28px; font-weight: 600; color: var(--primary); }
        
        /* Data Tables (ERP Style) */
        .table-container { background: var(--card-bg); border: 1px solid var(--border); border-radius: var(--radius); overflow-x: auto; margin-bottom: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .table-header { padding: 16px; border-bottom: 1px solid var(--border); font-weight: 600; background: #faf9f8; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); font-size: 13px; }
        th { font-weight: 600; color: var(--text-muted); background: var(--card-bg); position: sticky; top: 0; }
        tr:hover { background-color: var(--body-bg); }
        
        /* Badges */
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-in { background: #dff6dd; color: var(--success); }
        .badge-out { background: #fde7e9; color: var(--danger); }
        
        /* Utilities */
        .flex-between { display: flex; justify-content: space-between; align-items: center; }
    </style>
</head>
<body>
<div class="app-container">