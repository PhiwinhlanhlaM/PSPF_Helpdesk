<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PSPF Helpdesk Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Titillium+Web:wght@400;600&display=swap" rel="stylesheet">
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Titillium Web', sans-serif;
    }

    body {
        background-color: #f7f9fc;
        color: #333;
        padding: 20px;
    }

    /* Header */
    .dashboard-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .dashboard-header h1 {
        font-size: 1.6rem;
        color: #1e4976;
        font-weight: 600;
    }

    .dashboard-header button {
        background-color: #1e4976;
        color: white;
        padding: 10px 18px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        transition: 0.3s;
    }

    .dashboard-header button:hover {
        background-color: #153657;
    }

    /* Info Cards */
    .cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        transition: 0.3s;
    }

    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 14px rgba(0,0,0,0.08);
    }

    .card h3 {
        font-size: 1rem;
        color: #666;
        margin-bottom: 8px;
    }

    .card p {
        font-size: 1.4rem;
        font-weight: 600;
        color: #1e4976;
    }

    /* Tabs */
    .tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
    }

    .tab {
        flex: 1;
        text-align: center;
        background: white;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 12px;
        cursor: pointer;
        transition: 0.3s;
        font-weight: 500;
    }

    .tab.active {
        background: #1e4976;
        color: white;
        border: none;
    }

    .tab:hover {
        background: #1e4976;
        color: white;
    }

    /* Search and Filter Bar */
    .filter-bar {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        padding: 20px;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .search-box input {
        padding: 10px 14px;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        width: 250px;
    }

    .filter-actions button {
        background: #1e4976;
        color: white;
        border: none;
        padding: 10px 16px;
        border-radius: 8px;
        cursor: pointer;
        margin-left: 8px;
        transition: 0.3s;
    }

    .filter-actions button:hover {
        background: #153657;
    }

    /* Table */
    .table-container {
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        padding: 20px;
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    th, td {
        padding: 14px;
        text-align: left;
        border-bottom: 1px solid #f0f2f5;
    }

    th {
        background: #f4f6fb;
        color: #1e4976;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.9rem;
    }

    tr:hover {
        background: #f9fbff;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .dashboard-header {
            flex-direction: column;
            gap: 10px;
            align-items: flex-start;
        }

        .search-box input {
            width: 100%;
            margin-bottom: 10px;
        }

        .filter-actions {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
        }
    }
</style>
</head>
<body>

    <div class="dashboard-header">
        <h1>System Settings</h1>
        <button>Export Data</button>
    </div>

    <div class="cards">
        <div class="card">
            <h3>Total Users</h3>
            <p>132</p>
        </div>
        <div class="card">
            <h3>Departments</h3>
            <p>9</p>
        </div>
        <div class="card">
            <h3>Ticket Categories</h3>
            <p>12</p>
        </div>
        <div class="card">
            <h3>User Roles</h3>
            <p>4</p>
        </div>
    </div>

    <div class="tabs">
        <div class="tab active">Users Management</div>
        <div class="tab">System Structure</div>
    </div>

    <div class="filter-bar">
        <div class="search-box">
            <input type="text" placeholder="Search users...">
        </div>
        <div class="filter-actions">
            <button>Filter</button>
            <button>Add User</button>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Department</th>
                    <th>Role</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>John Doe</td>
                    <td>john.doe@pspf.co.sz</td>
                    <td>IT</td>
                    <td>Agent</td>
                    <td>Active</td>
                </tr>
                <tr>
                    <td>2</td>
                    <td>Jane Smith</td>
                    <td>jane.smith@pspf.co.sz</td>
                    <td>Finance</td>
                    <td>Admin</td>
                    <td>Active</td>
                </tr>
            </tbody>
        </table>
    </div>

</body>
</html>
