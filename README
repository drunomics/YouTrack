Youtrack API wrapper
====================

General classes to communicate with the YouTrack API and execute some commands.
Handles login, communication, parses YouTrack responses, and executes commands by project and issue ID.
Fetches issues as nice Issue entities.

Usage:

```
$api = new \YouTrack\YouTrackCommunicator(\Buzz\Browser, array(
    'uri' => 'http://your.youtrack.base',
    'username' => 'your_api_user',
    'password' => 'your_api_password'
));

$myIssue = $api->getIssue('MYPRJ-1');
var_dump($myIssue);

$api->executeCommands($myIssue, 'Fixed', 'I just closed this automagically.');
```

For more info on what commands you can use, check the YouTrack docs:
- https://confluence.jetbrains.com/display/YTD4/Command+Grammar
- https://confluence.jetbrains.com/display/YTD4/YouTrack+REST+API+Reference
- https://confluence.jetbrains.com/display/YTD4/Search+and+Command+Attributes
- https://confluence.jetbrains.com/display/YTD3/Apply+Command+to+an+Issue
- https://confluence.jetbrains.com/display/YTD3/Get+a+Version+Bundle