<?php
/**
 * AppName: YouTube Reseach Tool
 * Description: PHP Script search YouTube Videos, Display info on Channel  
 * Version: 2.1.5
 * Author: Jorge Pereira
 */

  
 // Enable error reporting for debugging (remove in production)
ini_set('display_errors', 0);
error_reporting(0);


require_once __DIR__ . '/vendor/autoload.php';

// Load configuration
$config = require_once __DIR__ . '/config.php';

class YouTubeSearch {
    private $client;
    private $youtube;
    private $txtRegistrationURL;
    private $appInfo;
    
    public function __construct($apiKey) {
        $this->client = new Google\Client();
        $this->client->setDeveloperKey($apiKey);
        $this->youtube = new Google\Service\YouTube($this->client);
        // Initialize appInfo in constructor
        $this->appInfo = $this->parseAppInfo();
    }

    
    public function parseAppInfo(): array {
        $defaultInfo = [
            'name' => 'App Name',
            'version' => '0.0.0',
            'author' => 'Jorge Pereira'
        ];

        // Get the content of the current file
        $content = file_get_contents(__FILE__);
        if ($content === false) {
            return $defaultInfo;
        }

        // Extract the doc block
        if (preg_match('/\/\*\*.*?\*\//s', $content, $matches)) {
            $docBlock = $matches[0];
            
            // Define patterns that exactly match your comment format
            $patterns = [
                'name' => '/\* AppName: ([^\n]+)/',
                'version' => '/\* Version: ([^\n]+)/',
                'author' => '/\* Author: ([^\n]+)/'
            ];

            $info = $defaultInfo; // Start with default values
            
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $docBlock, $matches)) {
                    $value = trim($matches[1]);
                    if (!empty($value)) {
                        $info[$key] = $value;
                    }
                }
            }
            
            return $info;
        }

        return $defaultInfo;
    }

    public function getAppInfo(): array {
        return $this->appInfo;
    }
    
    public function search(string $query, string $order = 'date'): array {
        try {
            // Map frontend sort options to valid YouTube API parameters
            $orderMap = [
                'newest' => 'date',        // Most recent first (default)
                'oldest' => 'date',        // We'll reverse results for oldest
                'views' => 'viewCount',    // Most views first
                'date' => 'date',          // Fallback for direct API parameter
                'viewCount' => 'viewCount' // Fallback for direct API parameter
            ];
            
            // Use mapped order or default to 'date'
            $apiOrder = isset($orderMap[$order]) ? $orderMap[$order] : 'date';
            
            // Search for both videos and channels
            // Note:  So each search could use around 150-200 quota points out of your daily 10,000. 
            $searchResponse = $this->youtube->search->listSearch('id,snippet', [
                'q' => $query,
                'maxResults' => 50,
                'type' => 'video,channel',
                'order' => $apiOrder
            ]);
            
            $results = [
                'videos' => [],
                'channels' => []
            ];
    
            $videoIds = [];
    
            // Process search results and separate videos and channels
            foreach ($searchResponse->getItems() as $item) {
                if ($item->getId()->getKind() === 'youtube#video') {
                    // Add to video IDs for detailed info
                    $videoIds[] = $item->getId()->getVideoId();
                } elseif ($item->getId()->getKind() === 'youtube#channel') {
                    // Get channel details
                    $channelResponse = $this->youtube->channels->listChannels(
                        'snippet,statistics',
                        ['id' => $item->getId()->getChannelId()]
                    );
                    
                    if (!empty($channelResponse->getItems())) {
                        $channel = $channelResponse->getItems()[0];
                        $results['channels'][] = [
                            'id' => $channel->getId(),
                            'title' => htmlspecialchars($channel->getSnippet()->getTitle()),
                            'description' => htmlspecialchars($channel->getSnippet()->getDescription()),
                            'thumbnail' => $channel->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                            'subscriberCount' => number_format($channel->getStatistics()->getSubscriberCount()),
                            'videoCount' => number_format($channel->getStatistics()->getVideoCount()),
                            'viewCount' => number_format($channel->getStatistics()->getViewCount()),
                            'link' => 'https://www.youtube.com/channel/' . $channel->getId()
                        ];
                    }
                }
            }
    
            // Get detailed video information if we have any videos
            if (!empty($videoIds)) {
                $videosResponse = $this->youtube->videos->listVideos(
                    'snippet,statistics',
                    ['id' => implode(',', $videoIds)]
                );
                
                foreach ($videosResponse->getItems() as $item) {
                    $results['videos'][] = [
                        'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                        'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                        'channelTitle' => htmlspecialchars($item->getSnippet()->getChannelTitle()),
                        'channelId' => $item->getSnippet()->getChannelId(),
                        'videoId' => $item->getId(),
                        'link' => 'https://www.youtube.com/watch?v=' . $item->getId(),
                        'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                        'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt())),
                        'viewCount' => number_format($item->getStatistics()->getViewCount()),
                        'likeCount' => number_format($item->getStatistics()->getLikeCount())
                    ];
                }
    
                // If oldest is selected, reverse the video array
                if ($order === 'oldest') {
                    $results['videos'] = array_reverse($results['videos']);
                }
            }
            
            return ['success' => true, 'data' => $results];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    } 
    public function getChannelInfo($channelId) {
        try {
            // Get channel details
            $response = $this->youtube->channels->listChannels('snippet,statistics,contentDetails', [
                'id' => $channelId
            ]);
            
            if (empty($response->getItems())) {
                throw new Exception('Channel not found');
            }
            
            $channel = $response->getItems()[0];
            $snippet = $channel->getSnippet();
            $statistics = $channel->getStatistics();
            
            // Get playlists count by actually fetching the playlists
            $playlistsResponse = $this->youtube->playlists->listPlaylists('id', [
                'channelId' => $channelId,
                'maxResults' => 50 // Get maximum allowed to get a better count
            ]);
            
            // Get total results from pageInfo
            $playlistCount = $playlistsResponse->getPageInfo()->getTotalResults();
            
            return [
                'success' => true,
                'data' => [
                    'channelId' => $channel->getId(),
                    'name' => $snippet->getTitle(),
                    'owner' => $snippet->getCustomUrl() ?? 'Not available',
                    'customUrl' => $snippet->getCustomUrl() ?? 'Not available',
                    'subscriberCount' => $statistics->getSubscriberCount() ?? 'Hidden',
                    'videoCount' => $statistics->getVideoCount() ?? '0',
                    'playlistCount' => $playlistCount ?? 0,
                    'startDate' => date('F j, Y', strtotime($snippet->getPublishedAt())),
                    'description' => $snippet->getDescription()
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }


public function getChannelVideos($channelId) {
    try {
        // First get channel details
        $channelResponse = $this->youtube->channels->listChannels('snippet', [
            'id' => $channelId
        ]);
        
        if (empty($channelResponse->getItems())) {
            throw new Exception('Channel not found');
        }
        
        $channel = $channelResponse->getItems()[0];
        $channelInfo = [
            'name' => $channel->getSnippet()->getTitle(),
            'id' => $channel->getId(),
            'thumbnail' => $channel->getSnippet()->getThumbnails()->getDefault()->getUrl()
        ];

        // Fetch channel videos
        $response = $this->youtube->search->listSearch('id,snippet', [
            'channelId' => $channelId,
            'type' => 'video',
            'order' => 'date', // Sort by most recent
            'maxResults' => 50
        ]);
        
        $videos = [];
        foreach ($response->getItems() as $item) {
            $videos[] = [
                'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                'channelTitle' => htmlspecialchars($item->getSnippet()->getChannelTitle()),
                'channelId' => $item->getSnippet()->getChannelId(),
                'videoId' => $item->getId()->getVideoId(),
                'link' => 'https://www.youtube.com/watch?v=' . $item->getId()->getVideoId(),
                'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt()))
            ];
        }
        
        return [
            'success' => true,
            'data' => [
                'channel' => $channelInfo,
                'videos' => $videos
            ]
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
    
    public function getChannelPlaylists($channelId) {
        try {
            // First get channel details
            $channelResponse = $this->youtube->channels->listChannels('snippet', [
                'id' => $channelId
            ]);
            
            if (empty($channelResponse->getItems())) {
                throw new Exception('Channel not found');
            }
            
            $channel = $channelResponse->getItems()[0];
            $channelInfo = [
                'name' => $channel->getSnippet()->getTitle(),
                'id' => $channel->getId(),
                'thumbnail' => $channel->getSnippet()->getThumbnails()->getDefault()->getUrl()
            ];
    
            $playlists = [];
            $pageToken = '';
            
            // Fetch all playlists using pagination
            do {
                $params = [
                    'channelId' => $channelId,
                    'maxResults' => 50
                ];
                
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }
                
                $response = $this->youtube->playlists->listPlaylists('snippet,contentDetails', $params);
                
                foreach ($response->getItems() as $item) {
                    $playlists[] = [
                        'id' => $item->getId(),
                        'title' => htmlspecialchars($item->getSnippet()->getTitle()),
                        'description' => htmlspecialchars($item->getSnippet()->getDescription()),
                        'videoCount' => $item->getContentDetails()->getItemCount(),
                        'thumbnail' => $item->getSnippet()->getThumbnails()->getMedium()->getUrl(),
                        'publishedAt' => date('F j, Y', strtotime($item->getSnippet()->getPublishedAt())),
                        'link' => 'https://www.youtube.com/playlist?list=' . $item->getId()
                    ];
                }
                
                $pageToken = $response->getNextPageToken();
            } while ($pageToken && count($playlists) < 200);
            
            return [
                'success' => true,
                'data' => [
                    'channel' => $channelInfo,
                    'playlists' => $playlists
                ]
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
  
}

// Initialize YouTubeSearch instance at the top level for footer access
$youtubeSearch = null;
try {
    $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
    $appInfo = $youtubeSearch->getAppInfo();
} catch (Exception $e) {
    $appInfo = [
        'name' => 'YouTube Research Tool',
        'version' => '0.0.0',
        'author' => 'Jorge Pereira'
    ];
}

// API endpoint handling
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    
    try {
        $youtubeSearch = new YouTubeSearch($config['youtube_api_key']);
        
        if (isset($_GET['query'])) {
            $order = isset($_GET['order']) ? $_GET['order'] : 'newest';
            echo json_encode($youtubeSearch->search($_GET['query'], $order));
        } elseif (isset($_GET['channelId'])) {
            echo json_encode($youtubeSearch->getChannelInfo($_GET['channelId']));
        } elseif (isset($_GET['channelPlaylists'])) {
            echo json_encode($youtubeSearch->getChannelPlaylists($_GET['channelPlaylists']));
        } elseif (isset($_GET['channelVideos'])) {
            echo json_encode($youtubeSearch->getChannelVideos($_GET['channelVideos']));
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Configuration error: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YouTube Search</title>
    <link rel="stylesheet" href="style.css">
    <style>
       /* future Use */
    </style>
</head>
<body>
<header>
        <div class="container">
            <div class="row">
                <div class="col text-center">
                    <h1>
                    <?php echo htmlspecialchars($appInfo['name']) ?>

                    </h1>
                </div>
            </div>
        </div>
</header>

<div class="search-container">
        <input type="text" id="search-input" placeholder="Search YouTube videos...">
        <select id="sort-order">
            <option value="newest">Most Recent</option>
            <option value="oldest">Oldest First</option>
            <option value="views">View Count</option>
        </select>
        <button id="search-button">Search</button>
    </div>
    
    <div id="results-container">
        <ul id="results-list"></ul>
    </div>

    <!-- Ensure modal is present in DOM before JavaScript loads -->
    <div id="channel-modal" style="display: none;">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <div id="channel-info-content"></div>
        </div>
    </div>

    
    <footer>
    <div class="container">
        <div class="footer-content">
            <?php 
            // Use the global $appInfo variable
            echo htmlspecialchars($appInfo['name']) . ' | ' .
                 'Version: ' . htmlspecialchars($appInfo['version']) . ' | ' .
                 'Author: ' . htmlspecialchars($appInfo['author']);
            ?>
        </div>
    </div>
    </footer>


    <script>
        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', () => {
            // Cache DOM elements

            const elements = {
                searchInput: document.getElementById('search-input'),
                searchButton: document.getElementById('search-button'),
                sortOrder: document.getElementById('sort-order'),
                resultsList: document.getElementById('results-list'),
                channelModal: document.getElementById('channel-modal'),
                channelInfoContent: document.getElementById('channel-info-content'),
                closeButton: document.querySelector('.close-button')
            };

            // Verify all elements exist
            const missingElements = Object.entries(elements)
                .filter(([key, element]) => !element)
                .map(([key]) => key);

            if (missingElements.length > 0) {
                console.error('Missing elements:', missingElements);
                return;
            }

            // Search functionality
            const performSearch = async () => {
    const query = elements.searchInput.value.trim();
    if (!query) return;

    try {
        elements.resultsList.innerHTML = '<div class="searching">Searching...</div>';
        const response = await fetch(
            `?query=${encodeURIComponent(query)}&order=${elements.sortOrder.value}`,
            {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'Search failed');
        }

        elements.resultsList.innerHTML = '';

        // Check if we have any results at all
        if (data.data.videos.length === 0 && data.data.channels.length === 0) {
            elements.resultsList.innerHTML = '<div class="error">No results found.</div>';
            return;
        }

        // Display channels first if any exist
        if (data.data.channels.length > 0) {
            const channelsSection = document.createElement('div');
            channelsSection.className = 'channels-section';
            channelsSection.innerHTML = '<h2>Channels</h2>';
            
            const channelsList = document.createElement('ul');
            channelsList.className = 'channels-list';

            data.data.channels.forEach(channel => {
                const li = document.createElement('li');
                li.className = 'channel-item';
                li.innerHTML = `
                    <div class="channel-grid">
                        <div class="channel-thumbnail">
                            <img 
                                src="${channel.thumbnail}" 
                                alt="${channel.title}"
                                onerror="this.src='/api/placeholder/120/120'; this.onerror=null;"
                            >
                        </div>
                        <div class="channel-info">
                            <h3><a href="${channel.link}" target="_blank">${channel.title}</a></h3>
                            <div class="channel-stats">
                                <span>${channel.subscriberCount} subscribers</span>
                                <span class="separator">•</span>
                                <span>${channel.videoCount} videos</span>
                                <span class="separator">•</span>
                                <span>${channel.viewCount} views</span>
                            </div>
                            <p class="description">${channel.description}</p>
                            <button class="view-channel-info" data-channel-id="${channel.id}">View Channel Info</button>
                        </div>
                    </div>
                `;
                channelsList.appendChild(li);
            });

            channelsSection.appendChild(channelsList);
            elements.resultsList.appendChild(channelsSection);
        }

        // Display videos section if any exist
        if (data.data.videos.length > 0) {
            const videosSection = document.createElement('div');
            videosSection.className = 'videos-section';
            videosSection.innerHTML = '<h2>Videos</h2>';

            const videosList = document.createElement('ul');
            videosList.className = 'videos-list';

            data.data.videos.forEach(video => {
                const li = document.createElement('li');
                li.className = 'video-item';
                li.innerHTML = `
                    <div class="thumbnail">
                        <img 
                            src="${video.thumbnail}" 
                            alt="${video.title}"
                            onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                        >
                    </div>
                    <div class="video-info">
                        <h3><a href="${video.link}" target="_blank">${video.title}</a></h3>
                        <div class="video-meta">
                            <div class="video-stats">
                                <span>${video.viewCount} views</span>
                                <span class="separator">•</span>
                                <span>${video.publishedAt}</span>
                                <span class="separator">•</span>
                                <span>${video.likeCount} likes</span>
                            </div>
                            <div class="channel">
                                <a class="channel-link" data-channel-id="${video.channelId}">${video.channelTitle}</a>
                            </div>
                        </div>
                        <p class="description">${video.description}</p>
                    </div>
                `;
                videosList.appendChild(li);
            });

            videosSection.appendChild(videosList);
            elements.resultsList.appendChild(videosSection);
        }

        // Add event listener for the new channel info buttons
        document.querySelectorAll('.view-channel-info').forEach(button => {
            button.addEventListener('click', () => {
                getChannelInfo(button.dataset.channelId);
            });
        });

    } catch (error) {
        console.error('Search error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching results: ${error.message}</div>
        `;
    }

};



const getChannelInfo = async (channelId) => {
    if (!channelId) return;

    try {
        const response = await fetch(`?channelId=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
           elements.sortOrder.addEventListener('change', performSearch);
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        const channelInfo = data.data;
        elements.channelInfoContent.innerHTML = `
            <h2>${channelInfo.name}</h2>
            <div class="channel-info">
                <p><strong>Channel ID:</strong> ${channelInfo.channelId}</p>
                <p><strong>Channel Name:</strong> ${channelInfo.name}</p>
                <p><strong>Channel Owner:</strong> ${channelInfo.owner}</p>
                <p><strong>Custom URL:</strong> ${channelInfo.customUrl}</p>
                <p><strong>Subscriber Count:</strong> ${channelInfo.subscriberCount}</p>
                <p><strong>Video Count:</strong> <a href="#" class="video-count-link" data-channel-id="${channelInfo.channelId}">${channelInfo.videoCount}</a></p>
                <p><strong>Number of Playlists:</strong> <a href="#" class="playlist-link" data-channel-id="${channelInfo.channelId}">${channelInfo.playlistCount}</a></p>
                <p><strong>Channel Start Date:</strong> ${channelInfo.startDate}</p>
                <p><strong>Channel Description:</strong> ${channelInfo.description}</p>
            </div>
            <a href="https://www.youtube.com/channel/${channelInfo.channelId}" 
               target="_blank" 
               class="channel-visit-button">
                Visit Channel
            </a>
        `;
        
        
        // Add event listeners for both playlist and video count links
        const playlistLink = elements.channelInfoContent.querySelector('.playlist-link');
        const videoCountLink = elements.channelInfoContent.querySelector('.video-count-link');
        
        playlistLink.addEventListener('click', async (e) => {
            e.preventDefault();
            const channelId = e.target.dataset.channelId;
            await showPlaylists(channelId);
            elements.channelModal.style.display = 'none';
        });

        videoCountLink.addEventListener('click', async (e) => {
            e.preventDefault();
            const channelId = e.target.dataset.channelId;
            await showChannelVideos(channelId);
            elements.channelModal.style.display = 'none';
        });
        
        elements.channelModal.style.display = 'block';
    } catch (error) {
        console.error('Channel info error:', error);
        alert(`Error fetching channel information: ${error.message}`);
    }
};

const showChannelVideos = async (channelId) => {
    try {
        elements.resultsList.innerHTML = '<div class="searching">Loading videos...</div>';
        
        const response = await fetch(`?channelVideos=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        // Create channel header
        elements.resultsList.innerHTML = `
            <div class="channel-header">
                <div class="channel-info-header">
                    <img 
                        src="${data.data.channel.thumbnail}" 
                        alt="${data.data.channel.name}" 
                        class="channel-thumbnail"
                        onerror="this.src='/api/placeholder/80/80'; this.onerror=null;"
                    >
                    <h2>
                        <a href="https://www.youtube.com/channel/${data.data.channel.id}" target="_blank">
                            ${data.data.channel.name}
                        </a>
                    </h2>
                </div>
                <h3>Channel Videos</h3>
            </div>
        `;

        if (data.data.videos.length === 0) {
            elements.resultsList.innerHTML += '<div class="error">No videos found.</div>';
            return;
        }

        const videosList = document.createElement('ul');
        videosList.className = 'video-list';

        data.data.videos.forEach(video => {
            const li = document.createElement('li');
            li.className = 'video-item';
            li.innerHTML = `
                <div class="thumbnail">
                    <img 
                        src="${video.thumbnail}" 
                        alt="${video.title}"
                        onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                    >
                </div>
                <div class="video-info">
                    <h3><a href="${video.link}" target="_blank">${video.title}</a></h3>
                    <div class="video-details">
                        <span>Published on ${video.publishedAt}</span>
                    </div>
                    <p class="description">${video.description}</p>
                </div>
            `;
            videosList.appendChild(li);
        });

        elements.resultsList.appendChild(videosList);

    } catch (error) {
        console.error('Videos error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching videos: ${error.message}</div>
        `;
    }
};


const showPlaylists = async (channelId) => {
    try {
        elements.resultsList.innerHTML = '<div class="searching">Loading playlists...</div>';
        
        const response = await fetch(`?channelPlaylists=${encodeURIComponent(channelId)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error);
        }

        // Create channel header with fallback image handling
        elements.resultsList.innerHTML = `
            <div class="channel-header">
                <div class="channel-info-header">
                    <img 
                        src="${data.data.channel.thumbnail}" 
                        alt="${data.data.channel.name}" 
                        class="channel-thumbnail"
                        onerror="this.src='/api/placeholder/80/80'; this.onerror=null;"
                    >
                    <h2>
                        <a href="https://www.youtube.com/channel/${data.data.channel.id}" target="_blank">
                            ${data.data.channel.name}
                        </a>
                    </h2>
                </div>
                <h3>Channel Playlists</h3>
            </div>
        `;

        if (data.data.playlists.length === 0) {
            elements.resultsList.innerHTML += '<div class="error">No playlists found.</div>';
            return;
        }

        // Create playlist list with fallback image handling
        const playlistList = document.createElement('ul');
        playlistList.className = 'playlist-list';

        data.data.playlists.forEach(playlist => {
            const li = document.createElement('li');
            li.className = 'video-item';
            li.innerHTML = `
                <div class="thumbnail">
                    <img 
                        src="${playlist.thumbnail}" 
                        alt="${playlist.title}"
                        onerror="this.src='/api/placeholder/120/90'; this.onerror=null;"
                    >
                </div>
                <div class="video-info">
                    <h3><a href="${playlist.link}" target="_blank">${playlist.title}</a></h3>
                    <div class="playlist-details">
                        <span>${playlist.videoCount} videos</span> • 
                        <span>Created on ${playlist.publishedAt}</span>
                    </div>
                    <p class="description">${playlist.description}</p>
                </div>
            `;
            playlistList.appendChild(li);
        });

        elements.resultsList.appendChild(playlistList);

    } catch (error) {
        console.error('Playlist error:', error);
        elements.resultsList.innerHTML = `
            <div class="error">Error fetching playlists: ${error.message}</div>
        `;
    }
};


            // Event listeners
             
 
            elements.searchButton.addEventListener('click', performSearch);
            elements.sortOrder.addEventListener('change', performSearch); // event listener for sort order change
            
            elements.searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });

            elements.resultsList.addEventListener('click', (e) => {
                const channelLink = e.target.closest('.channel-link');
                if (channelLink && channelLink.dataset.channelId) {
                    e.preventDefault();
                    getChannelInfo(channelLink.dataset.channelId);
                }
            });

            elements.closeButton.addEventListener('click', () => {
                elements.channelModal.style.display = 'none';
            });

            // Close modal when clicking outside
            window.addEventListener('click', (e) => {
                if (e.target === elements.channelModal) {
                    elements.channelModal.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>