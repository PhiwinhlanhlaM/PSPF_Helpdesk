<?php
function getUserDivision(mysqli $conn, string $username): ?array
{
    $sql = "
        SELECT 
            div.id   AS division_id,
            div.division_name
        FROM users u
        JOIN departments d ON u.department_id = d.id
        JOIN divisions div ON d.division_id = div.id
        WHERE u.username = ?
        
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $res = $stmt->get_result();
    return $res->num_rows ? $res->fetch_assoc() : null;
}
