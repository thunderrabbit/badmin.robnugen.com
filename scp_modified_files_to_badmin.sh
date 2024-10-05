#/bin/bash

DESTINATION_SET_IN_SSH_CONFIG="b.rn"
DEST_PATH="/home/thundergoblin/badmin.robnugen.com/"
SSH_KEY="/home/thunderrabbit/.ssh/b.robnugen.com"

# This will watch for changes in the source directory and scp them to the destination
inotifywait --exclude '.git/*' -mr -e close_write . | sed -ue 's/ CLOSE_WRITE,CLOSE //' | xargs -d$'\n' -I% scp  -P 22 -i $SSH_KEY % $DESTINATION_SET_IN_SSH_CONFIG:$DEST_PATH%

# inotifywait -mre close_write . | \      # m = monitor # r = resurse subdirs # e = these events
# sed -ue 's/ CLOSE_WRITE,CLOSE //' | \         # removing ' CLOSE_WRITE,CLOSE ' leaves us with exactly the file we need to save
# xargs -d$'\n' -I% \                # converts the stream into something scp can use
