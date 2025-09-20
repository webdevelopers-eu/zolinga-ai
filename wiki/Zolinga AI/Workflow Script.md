# Workflow Script

Zolinga AI Workflow Script is a powerful XML-based format for orchestrating AI interactions. It allows you to create complex multi-step AI workflows where each step can use results from previous steps, with built-in validation and retries.

## Schema

The workflow script follows the [XML Schema Definition](/modules/zolinga-ai/data/workflow.xsd) with namespace `http://www.zolinga.org/ai/workflow`.

To use the schema in your XML documents:

```xml
<workflow xmlns="http://www.zolinga.org/ai/workflow"
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:schemaLocation="http://www.zolinga.org/ai/workflow workflow.xsd">
  <!-- Your workflow content here -->
</workflow>
```

## Basic Structure

Workflow scripts consist of:

- **Root element**: `<workflow>` or `<ai>` (both are functionally equivalent)
- **Variables**: `<var>` elements for storing and passing data
- **AI processing blocks**: Nested `<ai>` elements for individual AI tasks
- **Prompts**: `<prompt>` elements with instructions for the AI
- **Tests**: `<test>` elements for validating AI-generated content
- **Return values**: `<return>` element for specifying output format

## Example: Fairy Tale Generator

Below is a complete example of a workflow that generates a fairy tale:

```xml
<!-- 
  Fairy Tale Generator Workflow
  This demonstrates how to create a multi-step AI workflow for creative content generation
  with validation between steps.
-->
<workflow xmlns="http://www.zolinga.org/ai/workflow"
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:schemaLocation="http://www.zolinga.org/ai/workflow workflow.xsd">
    
    <!-- Define initial settings as non-generated variables -->
    <var name="setting">enchanted forest</var>
    <var name="tone">whimsical and light-hearted</var>
    <var name="maxLength">500</var>
    
    <!-- 
      First AI step: Generate character names and attributes
      - Using required and pattern attributes to enforce constraints
      - Multiple options for name styles are available
    -->
    <ai>
        <prompt>
            Create memorable fantasy character names for a fairy tale set in an ${setting}.
            The tone should be ${tone}.
            
            Generate names for:
            1. A young protagonist (hero/heroine)
            2. A wise mentor character
            3. A magical creature/companion
            4. An antagonist
            
            Each name should sound fantasy-like but be easy to pronounce.
            For each character, also generate a single distinctive trait or quality.
        </prompt>
        
        <!-- Character names with pattern validation -->
        <var name="heroName" generate="yes" required="yes" pattern="^[A-Z][a-zA-Z\-]{2,20}$" />
        <var name="heroTrait" generate="yes" required="yes" />
        
        <var name="mentorName" generate="yes" required="yes" pattern="^[A-Z][a-zA-Z\-]{2,20}$" />
        <var name="mentorTrait" generate="yes" required="yes" />
        
        <var name="companionName" generate="yes" required="yes" pattern="^[A-Z][a-zA-Z\-]{2,20}$" />
        <var name="companionTrait" generate="yes" required="yes" />
        
        <var name="villainName" generate="yes" required="yes" pattern="^[A-Z][a-zA-Z\-]{2,20}$" />
        <var name="villainTrait" generate="yes" required="yes" />
        
        <!-- 
          Test the generated names - this will validate the AI's output
          If this test fails, the system will retry generating names.
          It must be yes/no question. By default 'yes' is expected.
          You can specify expect="no" to invert the logic.
        -->
        <test expect="yes">
            Are these fantasy character names appropriate for a ${tone} fairy tale?
            - Hero: ${heroName} (${heroTrait})
            - Mentor: ${mentorName} (${mentorTrait})
            - Companion: ${companionName} (${companionTrait})
            - Villain: ${villainName} (${villainTrait})
            
            Are all names unique from each other?
            Are all names easy to pronounce?
        </test>
        
        <!-- 
          Test with pattern matching - an alternative way to validate
          that ensures all names are truly different
        -->
        <test expect="yes">
            Are the names ${heroName}, ${mentorName}, ${companionName}, and ${villainName} all unique and different from each other?
        </test>
    </ai>

    <!-- 
      Second AI step: Generate a fairy tale plot outline
      - Using results from the first AI step
      - Applying additional constraints through patterns
    -->
    <ai>
        <prompt>
            Create a brief outline for a fairy tale with the following characters:
            - Protagonist: ${heroName}, who is ${heroTrait}
            - Mentor: ${mentorName}, who is ${mentorTrait}
            - Companion: ${companionName}, who is ${companionTrait}
            - Antagonist: ${villainName}, who is ${villainTrait}
            
            The setting is an ${setting} and the tone should be ${tone}.
            
            The outline should include:
            1. An initial situation/problem
            2. A challenge or quest
            3. How the characters work together
            4. A climactic confrontation
            5. A resolution
            
            Make it concise yet compelling, suitable for a short fairy tale.
        </prompt>
        
        <var name="plotOutline" generate="yes" required="yes" pattern=".{100,1000}" />
        
        <test>
            Does this plot outline include all the characters (${heroName}, ${mentorName}, ${companionName}, and ${villainName})?
            Does it have a clear beginning, middle, and end?
            Is it appropriate for a ${tone} fairy tale?
            
            Plot outline:
            ${plotOutline}
        </test>
    </ai>
    
    <!-- 
      Third AI step: Generate the full fairy tale
      - Using all previously generated content
      - Adding length constraints
    -->
    <ai>
        <prompt>
            Write a short fairy tale based on this outline:
            
            ${plotOutline}
            
            Characters:
            - Protagonist: ${heroName}, who is ${heroTrait}
            - Mentor: ${mentorName}, who is ${mentorTrait}
            - Companion: ${companionName}, who is ${companionTrait}
            - Antagonist: ${villainName}, who is ${villainTrait}
            
            The setting is an ${setting}.
            The tone should be ${tone}.
            Keep the story under ${maxLength} words.
            Give the story an engaging title.
            
            Structure the story with a title and narrative text.
        </prompt>
        
        <var name="storyTitle" generate="yes" required="yes" pattern="^.{3,100}$" />
        <var name="storyText" generate="yes" required="yes" pattern="^.{100,3000}$" />
        
        <test>
            Does this fairy tale include all the characters (${heroName}, ${mentorName}, ${companionName}, and ${villainName})?
            Is the tone ${tone}?
            Is it an engaging, complete story with a clear beginning, middle, and end?
            
            Title: ${storyTitle}
            
            Story:
            ${storyText}
        </test>
        
        <!-- 
          Using a pattern test to check word count
          Note how we use a regex to approximately count words.
          If pattern attribute is present then AI won't be used and instead
          the pattern will be applied directly to the content value.

          You can use expect="yes" or expect="no" to invert the logic.
        -->
        <test pattern="^(?:\s*\S+){1,${maxLength}}\s*$">
            ${storyText}
        </test>
    </ai>

    <!-- 
      Fourth AI step: Generate a moral/lesson from the story
      - An example of a simple single-output AI task
    -->
    <ai>
        <prompt>
            Based on the fairy tale "${storyTitle}" about ${heroName}'s adventure, create a short, one-sentence moral or lesson 
            that captures the essence of the story. The moral should be insightful but not preachy.

            Full story:
            ${storyText}
        </prompt>
        
        <var name="storyMoral" generate="yes" required="yes" pattern="^.{10,150}$" />
        
        <test>
            Is this moral related to the events in the story about ${heroName}?
            Is it expressed clearly in one sentence?
            Is it insightful without being too preachy?
            
            Moral: ${storyMoral}
        </test>
    </ai>

    <!-- 
      Return the complete fairy tale package as a structured object
      This demonstrates how to format the output as an array/object
    -->
    <return>
        <item name="title">${storyTitle}</item>
        <item name="characters">
            <item name="protagonist">
                <item name="name">${heroName}</item>
                <item name="trait">${heroTrait}</item>
            </item>
            <item name="mentor">
                <item name="name">${mentorName}</item>
                <item name="trait">${mentorTrait}</item>
            </item>
            <item name="companion">
                <item name="name">${companionName}</item>
                <item name="trait">${companionTrait}</item>
            </item>
            <item name="antagonist">
                <item name="name">${villainName}</item>
                <item name="trait">${villainTrait}</item>
            </item>
        </item>
        <item name="setting">${setting}</item>
        <item name="story">${storyText}</item>
        <item name="moral">${storyMoral}</item>
    </return>
</workflow>
```

## Key Components

### Variables

Variables store data for use in prompts or output. There are two types:

1. **Pre-defined variables** (provided as inputs):
   ```xml
   <var name="setting">enchanted forest</var>
   <var name="tone" value="whimsical and light-hearted"/> <!-- alternative syntax -->
   ```

2. **AI-generated variables** (output from AI prompts):
   ```xml
   <var name="heroName" generate="yes" required="yes" pattern="^[A-Z][a-zA-Z\-]{2,20}$" />
   ```

   Attributes:
   - `generate="yes"` - Value will be generated by AI
   - `required="yes"` - Value cannot be empty
   - `pattern="regex"` - Value must match the regular expression pattern

3. **Variables with options** (constrained choices for AI):
   ```xml
   <var name="storyType" generate="yes" required="yes">
     <option value="adventure"/>
     <option value="mystery"/>
     <option value="romance"/>
   </var>
   ```

### Prompts

Prompts provide instructions to the AI:

```xml
<prompt>
    Create a character named ${characterName} who lives in ${setting}.
</prompt>
```

You can also use the attribute form for short prompts:
```xml
<ai prompt="Generate a fairy tale title">
    <var name="title" generate="yes" required="yes"/>
</ai>
```

### Tests

Tests validate AI responses:

1. **Simple tests** (AI evaluates the answer):
   ```xml
   <test>
       Does this plot outline include all the characters?
       Is it appropriate for a ${tone} fairy tale?
       
       Plot outline:
       ${plotOutline}
   </test>
   ```

2. **Pattern tests** (regex validation):
   ```xml
   <test pattern="^(?:\s*\S+){1,${maxLength}}\s*$">
       ${storyText}
   </test>
   ```

3. **Tests with explicit expectation**:
   ```xml
   <test expect="yes">
       Is this character name appropriate for a children's story?
       ${characterName}
   </test>
   ```
   Note: `expect="yes"` is the default and can be omitted. You can use `expect="no"` to invert the logic. Works with simple regex patterns as well.

### Return Values

The `return` element specifies the output format. If omitted, the output is an array with all variables.

1. **Return a simple string**:
   ```xml
   <return>
       Once upon a time, ${heroName} lived in a ${setting}...
   </return>
   ```

2. **Return a structured object**:
   ```xml
   <return>
       <item name="title">${storyTitle}</item>
       <item name="protagonist">${heroName}</item>
       <item name="story">${storyText}</item>
   </return>
   ```

3. **Return nested objects**:
   ```xml
   <return>
       <item name="characters">
           <item name="hero">${heroName}</item>
           <item name="villain">${villainName}</item>
       </item>
   </return>
   ```

## Using Workflow Scripts

To execute a workflow script:

```php
global $api;

$workflowFile = 'private://my-module/path/to/workflow.xml';

// Process workflow with optional initial data
$result = $api->ai->runWorkflowFile($workflowFile, [
    'setting' => 'underwater kingdom',
    'tone' => 'mysterious'
]);

// $result will contain the data structure defined by the <return> element
```

## Best Practices

1. **Add detailed comments** to explain complex sections
2. **Use variables for reusable content** rather than repeating text
3. **Start with simple steps** before building more complex workflows
4. **Test extensively** with different inputs
5. **Use pattern validation** for critical output formats
6. **Add clear error messages** in test elements

## Tips for AI Prompts

1. Be specific and clear
2. Include examples when needed
3. Break complex tasks into smaller steps
4. Use context from previous steps
5. Specify format requirements clearly
