# ethers-pure-php
## Created by [https://nirvanalabs.io](Nirvana Labs)

### LTS RELEASE
Created for ultimate stability.

### Contributing
CC0 License - The master branch will remain as the LTS stable branch. Make any contributions and bug reports to the dev branch.

### Zero External Dependencies
- Depends on several PHP extensions(bcmatch and bcmath), so it's better to run it with docker

### Build docker image
```shell
docker build -t practice:php .
```

### Run mint.php
```shell
docker run -it --init --rm -v "$PWD":/data practice:php php mint.php
```

### Team
@0xdevin
@codevona
@dhruvinparikh