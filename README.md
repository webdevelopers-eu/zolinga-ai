# Zolinga AI

Zolinga AI is a module providing AI features for the [Zolinga](https://github.com/webdevelopers-eu/zolinga) platform.

## Features

- **AI Service (`$api->ai`)**: Access AI backends for content generation.  
- **CMS Content Elements**: Integrate AI-powered `<ai-article>` within Zolinga’s CMS.  
- **Backend Configuration**: Multiple backends for load balancing or varied models.  

## Usage

1. Configure the AI backends in your Zolinga configuration:
   ```json
   {
       "config": {
           "ai": {
               "backends": {
                   "default": {
                       "type": "ollama",
                       "uri": "http://login:password@127.0.0.1:3000/"
                   }
               }
           }
       }
   }

2. Use <ai-article> in templates:
```html
<ai-article model="deepseek-r1:8b" backend="default">Generate a quick intro.</ai-article>
```

3. Run the `./bin/zolinga ai:generate` command to process queued articles.