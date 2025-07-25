<?php

use OpenAI\Responses\Chat\CreateResponseChoice;
use OpenAI\Responses\Chat\CreateResponseChoiceAudio;
use OpenAI\Responses\Chat\CreateResponseChoiceLogprobs;
use OpenAI\Responses\Chat\CreateResponseMessage;

test('from', function () {
    $result = CreateResponseChoice::from(chatCompletion()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBeIn(['stop', null]);
});

test('from without logprobs', function () {
    $result = CreateResponseChoice::from(chatCompletionWithoutLogprobs()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBeIn(['stop', null]);
});

test('from with logprobs', function () {
    $result = CreateResponseChoice::from(chatCompletionWithLogprobs()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeInstanceOf(CreateResponseChoiceLogprobs::class)
        ->finishReason->toBeIn(['stop', null]);
});

test('from vision response', function () {
    $result = CreateResponseChoice::from(chatCompletionFromVision()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBeNull();
});

test('from audio modality response', function () {
    $result = CreateResponseChoice::from(chatCompleteAudioModality()['choices'][0]);

    expect($result)
        ->message->audio->toBeInstanceOf(CreateResponseChoiceAudio::class);

    expect($result->message->audio)
        ->id->toBe('audio_1234567890abcdef')
        ->data->toBe('base64-encoded-audio-data')
        ->expiresAt->toBe(1700000000)
        ->transcript->toBe('This is a sample transcript of the audio.');
});

test('from OpenRouter OpenAI response', function () {
    $result = CreateResponseChoice::from(chatCompletionOpenRouterOpenAI()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBe('stop');
});

test('from OpenRouter Google response', function () {
    $result = CreateResponseChoice::from(chatCompletionOpenRouterGoogle()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBe('stop');
});

test('from OpenRouter xAI response', function () {
    $result = CreateResponseChoice::from(chatCompletionOpenRouterXAI()['choices'][0]);

    expect($result)
        ->index->toBe(0)
        ->message->toBeInstanceOf(CreateResponseMessage::class)
        ->logprobs->toBeNull()
        ->finishReason->toBe('stop');
});

test('to array', function () {
    $result = CreateResponseChoice::from(chatCompletion()['choices'][0]);

    expect($result->toArray())
        ->toBe(chatCompletion()['choices'][0]);
});

test('to array with logprobs', function () {
    $result = CreateResponseChoice::from(chatCompletionWithLogprobs()['choices'][0]);

    expect($result->toArray())
        ->toBe(chatCompletionWithLogprobs()['choices'][0]);
});
