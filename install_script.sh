#!/bin/bash

#git clone https://github.com/godamri/connect.git
#cd ./connect
#rm -rf .git
#composer install
echo '1.0.0' | php application app:build connect
sudo mkdir -p /opt/connect
sudo cp $(pwd)/builds/connect /opt/connect
sudo ln -sf /opt/connect/connect /usr/local/bin