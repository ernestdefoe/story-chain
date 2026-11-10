<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A story written one line per round. `round` is the round now open (or, once
 * finished, the next one that never opened); `round_ends_at` is when it
 * closes. The counters are kept by the Closer so the library and the index
 * card never count rows per story.
 */
return Migration::createTable('story_chain_stories', function (Blueprint $table) {
    $table->increments('id');
    $table->string('title', 120);
    $table->string('slug', 120);
    $table->string('blurb', 300)->nullable();
    $table->string('cover', 20)->default('oxblood');
    $table->unsignedInteger('user_id')->nullable();
    $table->string('status', 10)->default('active');
    $table->unsignedInteger('round')->default(1);
    $table->dateTime('round_ends_at');
    $table->unsignedSmallInteger('round_hours')->default(24);
    $table->unsignedSmallInteger('max_lines')->nullable();
    $table->unsignedSmallInteger('max_length')->default(200);
    $table->boolean('final_round')->default(false);
    $table->unsignedInteger('line_count')->default(0);
    $table->unsignedInteger('contributor_count')->default(0);
    $table->unsignedInteger('candidate_count')->default(0);
    $table->unsignedInteger('vote_count')->default(0);
    $table->dateTime('last_line_at')->nullable();
    $table->dateTime('finished_at')->nullable();
    $table->dateTime('created_at');
    $table->dateTime('updated_at')->nullable();

    // Short explicit names: prefixed index names must stay under 64 characters.
    $table->index(['status', 'round_ends_at'], 'sc_stories_status_ends');
    $table->index('user_id', 'sc_stories_user');
    $table->foreign('user_id', 'sc_stories_user_fk')->references('id')->on('users')->nullOnDelete();
});
