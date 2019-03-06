#!/bin/bash
# TODO: Move this to a docker container as installing the oracle client takes quite long

# build php module
apt-get update && apt-get install -qq --force-yes libaio1 sudo
git clone https://github.com/DeepDiver1975/oracle_instant_client_for_ubuntu_64bit.git instantclient
cd instantclient
bash -c 'printf "\n" | python system_setup.py'

export ORACLE_HOME=/usr/lib/oracle/11.2/client64

mkdir -p /usr/lib/oracle/11.2/client64/rdbms/
ln -s /usr/include/oracle/11.2/client64/ /usr/lib/oracle/11.2/client64/rdbms/public

printf "/usr/lib/oracle/11.2/client64\n" | pecl install oci8

# TODO: make this work on other php versions
echo "extension=oci8.so" >> /etc/php/7.1/mods-available/oci8.ini
phpenmod oci8

