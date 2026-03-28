<?php

try{
    
    $req = $_SERVER['REQUEST_METHOD'];
    
    if($req !== 'GET' && $req !== 'POST'){
        throw new Exception("Page wasn't found!", 404);
    }

    http_response_code(200);
    echo json_encode(["message" => "Welcome to Smartbooks API 😊"]);
    exit;

}catch(Exception $e){
    // Handle errors properly
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);

}
?>
