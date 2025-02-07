<?php

namespace Zolinga\AI\Enum;

enum PromptStatusEnum: string {
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
}