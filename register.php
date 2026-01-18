<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
define('DB_HOST', 'localhost:3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'nu_students_db');

// Create database connection
function getDatabaseConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        sendErrorResponse('Database connection failed: ' . $conn->connect_error, 500);
    }
    
    return $conn;
}

// Helper function to validate email format
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Helper function to send JSON response
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit();
}

// Helper function to send error response
function sendErrorResponse($message, $statusCode = 400) {
    sendResponse([
        'success' => false,
        'message' => $message
    ], $statusCode);
}

// Validate student data
function validateStudentData($data) {
    $errors = [];
    
    // Check if student name is present and not empty
    if (!isset($data['studentName']) || trim($data['studentName']) === '') {
        $errors[] = 'Student name is required';
    }
    
    // Check if email is present and valid
    if (!isset($data['email']) || trim($data['email']) === '') {
        $errors[] = 'Email address is required';
    } elseif (!isValidEmail($data['email'])) {
        $errors[] = 'Invalid email address format';
    }
    
    // Check if course is selected
    if (!isset($data['course']) || trim($data['course']) === '') {
        $errors[] = 'Course selection is required';
    }
    
    return $errors;
}

// Clean and sanitize input data
function sanitizeData($data) {
    return [
        'studentName' => trim(htmlspecialchars($data['studentName'])),
        'email' => trim(strtolower($data['email'])),
        'course' => trim(htmlspecialchars($data['course']))
    ];
}

// MAIN API ROUTING
$method = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// POST /register.php - Register a new student
if ($method === 'POST') {
    // Get JSON input
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);
    
    // Check if JSON is valid
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendErrorResponse('Invalid JSON data');
    }
    
    // Validate the data
    $validationErrors = validateStudentData($data);
    
    if (!empty($validationErrors)) {
        sendResponse([
            'success' => false,
            'message' => 'Missing or Invalid Data',
            'errors' => $validationErrors
        ], 400);
    }
    
    // Sanitize input data
    $cleanData = sanitizeData($data);
    
    // Connect to database
    $conn = getDatabaseConnection();
    
    // Check if email already exists
    $checkStmt = $conn->prepare("SELECT id FROM students WHERE email = ?");
    $checkStmt->bind_param("s", $cleanData['email']);
    $checkStmt->execute();
    $checkStmt->store_result();
    
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        $conn->close();
        sendResponse([
            'success' => false,
            'message' => 'Email address already registered'
        ], 409);
    }
    $checkStmt->close();
    
    // Insert new student
    $insertStmt = $conn->prepare("INSERT INTO students (studentName, email, course, registrationDate) VALUES (?, ?, ?, NOW())");
    $insertStmt->bind_param("sss", $cleanData['studentName'], $cleanData['email'], $cleanData['course']);
    
    if ($insertStmt->execute()) {
        $studentId = $insertStmt->insert_id;
        
        sendResponse([
            'success' => true,
            'message' => 'Registration Successful',
            'data' => [
                'id' => $studentId,
                'studentName' => $cleanData['studentName'],
                'email' => $cleanData['email'],
                'course' => $cleanData['course']
            ]
        ], 201);
    } else {
        sendErrorResponse('Failed to register student: ' . $insertStmt->error, 500);
    }
    
    $insertStmt->close();
    $conn->close();
}

// GET /register.php?action=list - Get all students (bonus feature)
elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list') {
    $conn = getDatabaseConnection();
    
    $result = $conn->query("SELECT id, studentName, email, course, registrationDate FROM students ORDER BY registrationDate DESC");
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    
    sendResponse([
        'success' => true,
        'count' => count($students),
        'data' => $students
    ]);
    
    $conn->close();
}

// GET /register.php?action=view&id=1 - Get specific student (bonus feature)
elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['id'])) {
    $studentId = intval($_GET['id']);
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("SELECT id, studentName, email, course, registrationDate FROM students WHERE id = ?");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        $conn->close();
        sendErrorResponse('Student not found', 404);
    }
    
    $student = $result->fetch_assoc();
    
    sendResponse([
        'success' => true,
        'data' => $student
    ]);
    
    $stmt->close();
    $conn->close();
}

// DELETE /register.php?id=1 - Delete a student (bonus feature)
elseif ($method === 'DELETE' && isset($_GET['id'])) {
    $studentId = intval($_GET['id']);
    $conn = getDatabaseConnection();
    
    $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
    $stmt->bind_param("i", $studentId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        sendResponse([
            'success' => true,
            'message' => 'Student removed successfully'
        ]);
    } else {
        sendErrorResponse('Student not found', 404);
    }
    
    $stmt->close();
    $conn->close();
}

// GET /register.php - Health check
elseif ($method === 'GET') {
    sendResponse([
        'success' => true,
        'message' => 'NU Student Registration API is running',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

// Method not allowed
else {
    sendErrorResponse('Method not allowed', 405);
}
?>