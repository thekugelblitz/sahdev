<?php
$messagesText = 'Here is a screenshot: https://prnt.sc/MatFeFEuOUgz and a direct link: https://via.placeholder.com/150.png';

$images = [];

if (preg_match_all('/https?:\/\/[^\s"\'<>]+?\.(?:png|jpg|jpeg|gif|webp)(?:\?[^\s"\'<>]+)?/i', $messagesText, $matches)) {
    echo "Direct Matches:\n";
    print_r($matches[0]);
} else {
    echo "No Direct Matches\n";
}

if (preg_match_all('/https?:\/\/prnt\.sc\/[a-zA-Z0-9_-]+/i', $messagesText, $matches)) {
    echo "Prnt.sc Matches:\n";
    print_r($matches[0]);
} else {
    echo "No Prnt.sc Matches\n";
}

// Test cURL
$prntScUrl = "https://prnt.sc/MatFeFEuOUgz";
$html = @file_get_contents($prntScUrl);
if ($html) {
    if (preg_match('/<img[^>]+(?:id="screenshot-image"|class="[^"]*screenshot-image[^"]*")[^>]+src="([^"]+)"/i', $html, $imgMatches)) {
        echo "Extracted PRNT.SC img src: " . $imgMatches[1] . "\n";
    } else {
        echo "Failed to extract img src from PRNT.SC html\n";
    }
} else {
    echo "Failed to fetch PRNT.SC HTML\n";
}
