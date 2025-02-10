# Zolinga AI

Zolinga AI is a module providing AI features for the [Zolinga](https://github.com/webdevelopers-eu/zolinga) platform.

## Features

- **AI Service (`$api->ai`)**: Access AI backends for content generation.  
- **CMS Content Elements**: Integrate AI-powered `<ai-text>` within Zolinga’s CMS.  
- **Backend Configuration**: Multiple backends for load balancing or varied models.  

## Usage

1. Configure the AI backends in your Zolinga configuration. The module supports the [Ollama backend API](https://ollama.com/download). The `default` backend definition is equired. Incomplete backend definitions will inherit missing options from the `default` backend.
   ```json
   {
       "config": {
           "ai": {
               "backends": {
                   "default": {
                       "type": "ollama",
                       "url": "http://login:password@127.0.0.1:3000/",
                       "model": "deepseek-r1:8b"
                   },
                   "fast": {
                        "model": "deepseek-r1:1.5b",
                   }
               }
           }
       }
   }
   ```
2. Use <ai-text> in HTML content generated by [Zolinga CMS](https://github.com/webdevelopers-eu/zolinga-cms):
```html
<ai-text ai="fast">
    Generate a quick intro for my blog about photography.
</ai-text>
```

3. Run the `./bin/zolinga ai:generate` command to process queued articles in the background.  
   - Use `--loop` to run continuously.  
   - Use without options to process all queued articles and exit.